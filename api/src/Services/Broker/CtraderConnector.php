<?php

namespace App\Services\Broker;

use App\Enums\CtraderEnvironment;
use GuzzleHttp\Client as HttpClient;
use WebSocket\Client as WsClient;

class CtraderConnector implements ConnectorInterface
{
    use TracksLastTestError;
    use NormalizesInUserTimezone;

    /**
     * ProtoOAPayloadType numeric codes (OpenApiModelMessages.proto). The
     * cTrader JSON API keys every frame by these integers — NOT the message
     * name — with the message fields nested under a `payload` object.
     */
    private const PAYLOAD_TYPES = [
        'ProtoOAApplicationAuthReq' => 2100,
        'ProtoOAAccountAuthReq'     => 2102,
        'ProtoOAReconcileReq'       => 2124,
        'ProtoOATraderReq'          => 2121,
        'ProtoOAOrderListReq'       => 2175,
        'ProtoOADealListReq'        => 2133,
        'ProtoOAGetAccountListByAccessTokenReq' => 2149,
        'ProtoOAAssetListReq'       => 2112,
        'ProtoOASymbolsListReq'     => 2114,
        'ProtoOASymbolByIdReq'      => 2116,
        'ProtoOANewOrderReq'        => 2106,
        'ProtoOACancelOrderReq'     => 2108,
        'ProtoOAClosePositionReq'   => 2111,
        'ProtoOAErrorRes'           => 2142,
    ];

    /** Base-protocol heartbeat (ProtoPayloadType.HEARTBEAT_EVENT). */
    private const HEARTBEAT_EVENT = 51;

    /**
     * Accounts enriched with balance/currency in one discovery call. Enrichment
     * costs two to three round trips each, so an unbounded list would keep the
     * HTTP request open long enough to time out.
     */
    private const MAX_ENRICHED_ACCOUNTS = 40;

    private array $config;
    private ?WsClient $wsClient;
    private ?HttpClient $httpClient;
    private int $msgCounter = 0;
    private ?string $lastBalanceCurrency = null;

    /**
     * symbolId → symbolName per account, memoised for one sync run. The light
     * symbol list covers the broker's whole universe and a single sync resolves
     * symbols three times (deals, open positions, pending orders).
     *
     * @var array<int, array<int, string>>
     */
    private array $symbolNameCache = [];

    /**
     * Partial closes seen by fetchDeals on positions that are still open,
     * keyed by positionId. Consumed by fetchOpenPositions in the same run so
     * a TP1 lands as a partial exit on the live position rather than as a
     * separate closed trade.
     *
     * @var array<int, list<array{exit_price: float, size: float, pnl: float, closed_at: string, external_id: string}>>
     */
    private array $pendingPartialExits = [];

    /**
     * The authenticated socket held open for the length of one sync run, and
     * the account it is authenticated against.
     *
     * A run asks the connector five separate questions (deals, open positions,
     * pending orders, closed orders, balance). Opening a socket per question
     * meant re-sending ApplicationAuth + AccountAuth five times: ten of the
     * ~21 requests a run cost were spent re-proving who we are. FTMO disables a
     * trading account past 2 000 requests a day, and a 15-minute interval put a
     * single connection at ~2 016.
     */
    private ?WsClient $session = null;
    private ?int $sessionAccountId = null;

    /**
     * Opt-in: only a sync run keeps its socket open between calls. placeOrder
     * and friends run inside an HTTP request, where holding a socket would leak
     * one per web request.
     */
    private bool $sessionReuse = false;

    /**
     * ProtoOAReconcileReq response, memoised per account for one run. Deals,
     * open positions and pending orders all want the same snapshot, and asking
     * three times seconds apart costs three requests to describe one state.
     *
     * @var array<int, array>
     */
    private array $reconcileCache = [];

    /**
     * symbolId → lotSize, memoised for one run alongside the names. Symbols are
     * resolved three times over per run and only the names were cached, so
     * ProtoOASymbolByIdReq went out every time.
     *
     * @var array<int, array<int, int>>
     */
    private array $lotSizeCache = [];

    /**
     * Requests actually put on the wire since the run opened, by message name.
     *
     * Working out what a sync cost meant reading this class line by line when
     * FTMO disabled account 7589848 for "amount of activity". A budget nobody
     * can measure is a budget nobody notices going over: the count belongs in
     * the logs, and the connector is the only thing that can know it.
     *
     * @var array<string, int>
     */
    private array $requestCounts = [];

    public function __construct(array $config, ?WsClient $wsClient = null, ?HttpClient $httpClient = null)
    {
        $this->config = $config;
        $this->wsClient = $wsClient;
        $this->httpClient = $httpClient;
    }

    public function fetchDeals(array $credentials, ?string $sinceCursor = null): array
    {
        // 1-2. Application then account auth, on the run's shared socket when
        //      there is one.
        $ws = $this->acquireSession($credentials);

        try {
            // 3. Live snapshot first. It answers two questions the deal list
            //    alone cannot: which positions are still open (so a partial
            //    close is not mistaken for a finished trade), and how far back
            //    the window must reach to cover them. Memoised for the run —
            //    fetchOpenPositions and fetchOpenOrders want the same one.
            $reconcile = $this->reconcile($ws, (int) $credentials['ctid_trader_account_id']);
            $openPositions = $reconcile['position'] ?? [];
            $openPositionIds = [];
            $oldestOpenTimestamp = null;
            foreach ($openPositions as $position) {
                if (!isset($position['positionId'])) {
                    continue;
                }
                $openPositionIds[(int) $position['positionId']] = true;
                $openedAt = (int) ($position['tradeData']['openTimestamp'] ?? 0);
                if ($openedAt > 0 && ($oldestOpenTimestamp === null || $openedAt < $oldestOpenTimestamp)) {
                    $oldestOpenTimestamp = $openedAt;
                }
            }

            // 4. Fetch deals with pagination
            $allDeals = [];
            $toTimestamp = (int) (time() * 1000);
            $fromTimestamp = $this->windowStart($sinceCursor, $toTimestamp);

            // The cursor only moves forward, so a position opened before it
            // would have its partial closes fall outside every later window and
            // the exits would be lost for good. Pull the window back to the
            // oldest position still open; already-imported closed positions in
            // the widened range are skipped downstream on external_id.
            if ($oldestOpenTimestamp !== null && $oldestOpenTimestamp < $fromTimestamp) {
                $fromTimestamp = $oldestOpenTimestamp;
            }
            $maxRows = 1000;

            do {
                $response = $this->sendAndReceive($ws, 'ProtoOADealListReq', [
                    'ctidTraderAccountId' => $credentials['ctid_trader_account_id'],
                    'fromTimestamp' => $fromTimestamp,
                    'toTimestamp' => $toTimestamp,
                    'maxRows' => $maxRows,
                ]);

                $deals = $response['deal'] ?? [];
                $allDeals = array_merge($allDeals, $deals);
                $hasMore = $response['hasMore'] ?? false;

                if ($hasMore && !empty($deals)) {
                    $lastTimestamp = end($deals)['executionTimestamp'] ?? $toTimestamp;
                    $fromTimestamp = $lastTimestamp + 1;
                }
            } while ($hasMore);

            // 5. Resolve symbol IDs to names and lot sizes
            // array_values() is load-bearing: array_unique() keeps the original
            // keys, so deduplicating repeated symbols leaves gaps (0, 2, 4) and
            // json_encode then emits an OBJECT instead of an array. cTrader
            // reads symbolId as a repeated int64 and rejects the whole request
            // with "Couldn't parse integer: For input string: {".
            $symbolIds = array_values(array_unique(array_column($allDeals, 'symbolId')));
            $symbolMap = $this->resolveSymbols($ws, $credentials['ctid_trader_account_id'], $symbolIds);

            // A position's open time is on its OPENING deal (the one without a
            // closePositionDetail), never on the closing one. Pair them up here
            // so the normalizer stops dating trades from the moment they closed.
            $positionOpenTimestamps = [];
            foreach ($allDeals as $deal) {
                if (isset($deal['closePositionDetail']) || !isset($deal['positionId'])) {
                    continue;
                }
                $positionId = (int) $deal['positionId'];
                $openedAt = (int) ($deal['executionTimestamp'] ?? $deal['createTimestamp'] ?? 0);
                if ($openedAt > 0 && ($positionOpenTimestamps[$positionId] ?? PHP_INT_MAX) > $openedAt) {
                    $positionOpenTimestamps[$positionId] = $openedAt;
                }
            }

            foreach ($allDeals as &$deal) {
                $symbolId = (int) ($deal['symbolId'] ?? 0);
                $deal['symbolName'] = $symbolMap[$symbolId]['name'] ?? ('UNKNOWN_' . $symbolId);
                $deal['lotSize'] = $symbolMap[$symbolId]['lot_size'] ?? 0;
                $positionId = (int) ($deal['positionId'] ?? 0);
                if (isset($positionOpenTimestamps[$positionId])) {
                    $deal['positionOpenTimestamp'] = $positionOpenTimestamps[$positionId];
                }
            }
            unset($deal);

            $this->releaseSession($ws);
        } catch (\Throwable $e) {
            $this->discardSession($ws);
            throw $e;
        }

        // 6. A closing deal on a position that is STILL open is a partial exit
        //    (a TP1), not a finished trade. Emitting it as one created a
        //    phantom position beside the live one — a "buy with an instant
        //    profit" facing the short that was actually running. Hold those
        //    deals back and hand them to fetchOpenPositions instead.
        [$closedDeals, $stillOpenDeals] = $this->splitDealsByPositionState($allDeals, $openPositionIds);
        $this->pendingPartialExits = $this->buildPartialExits($stillOpenDeals);

        $normalized = $this->normalizeDeals($closedDeals);

        return [
            'deals' => $normalized,
            'cursor' => $this->cursorFrom($closedDeals),
            'raw_count' => count($allDeals),
        ];
    }

    /**
     * Live open positions via ProtoOAReconcileReq → position[]. Best-effort:
     * returns an empty snapshot when credentials are missing so the diff
     * service treats it as "no broker open positions this run" rather than
     * fataling.
     */
    public function fetchOpenPositions(array $credentials): array
    {
        if (empty($credentials['ctid_trader_account_id']) || empty($credentials['access_token'])) {
            return ['positions' => [], 'raw_count' => 0];
        }

        return $this->withAuthenticatedSession($credentials, function (WsClient $ws, int $accountId) {
            // The run's shared snapshot. It always carries the protection
            // orders — see reconcile() for why that flag is not optional.
            $response = $this->reconcile($ws, $accountId);
            $positions = $response['position'] ?? [];
            $takeProfitOrders = $this->collectStagedTakeProfits($response['order'] ?? []);

            $symbolMap = $this->resolveSymbols($ws, $accountId, $this->collectSymbolIds($positions));

            $normalizer = $this->normalizer();
            $normalized = [];
            foreach ($positions as $position) {
                $position = $this->withSymbol($position, $symbolMap);
                $position['takeProfitOrders'] = $takeProfitOrders[(int) ($position['positionId'] ?? 0)] ?? [];
                $this->reportUnresolvedTakeProfits($position, $response['order'] ?? []);
                // Partial closes fetchDeals held back earlier in this same sync
                // run: attaching them here is what makes a TP1 a partial exit of
                // the live position and lets the original size be rebuilt.
                $position['partialExits'] = $this->pendingPartialExits[(int) ($position['positionId'] ?? 0)] ?? [];
                $row = $normalizer->normalizeCtraderOpenPosition($position);
                if ($row !== null) {
                    $normalized[] = $row;
                }
            }

            return ['positions' => $normalized, 'raw_count' => count($positions)];
        });
    }

    /**
     * Live pending orders via ProtoOAReconcileReq → order[]. Protective
     * closing orders (SL/TP bound to an open position) are excluded — they're
     * already represented via the position's own sl/tp, not standalone
     * pending orders the user placed.
     */
    public function fetchOpenOrders(array $credentials): array
    {
        if (empty($credentials['ctid_trader_account_id']) || empty($credentials['access_token'])) {
            return ['orders' => [], 'raw_count' => 0];
        }

        return $this->withAuthenticatedSession($credentials, function (WsClient $ws, int $accountId) {
            $response = $this->reconcile($ws, $accountId);
            $rawOrders = $response['order'] ?? [];

            // The shared snapshot carries the protection orders too; this
            // filter — which predates the sharing — is what keeps them out.
            $orders = array_values(array_filter($rawOrders, fn($o) => empty($o['closingOrder'])));

            $symbolMap = $this->resolveSymbols($ws, $accountId, $this->collectSymbolIds($orders));

            $normalizer = $this->normalizer();
            $normalized = [];
            foreach ($orders as $order) {
                $order = $this->withSymbol($order, $symbolMap);
                $row = $normalizer->normalizeCtraderOpenOrder($order);
                if ($row !== null) {
                    $normalized[] = $row;
                }
            }

            return ['orders' => $normalized, 'raw_count' => count($rawOrders)];
        });
    }

    /**
     * Recently-closed orders via ProtoOAOrderListReq (orders in a time
     * window). Only terminal states (filled/cancelled/expired) survive
     * normalization — the order diff uses them to disambiguate why a pending
     * order disappeared, instead of always defaulting to CANCELLED.
     */
    public function fetchClosedOrders(array $credentials, ?string $sinceCursor = null): array
    {
        if (empty($credentials['ctid_trader_account_id']) || empty($credentials['access_token'])) {
            return ['orders' => [], 'raw_count' => 0];
        }

        return $this->withAuthenticatedSession($credentials, function (WsClient $ws, int $accountId) use ($sinceCursor) {
            $toTimestamp = (int) (time() * 1000);
            $fromTimestamp = $this->windowStart($sinceCursor, $toTimestamp);

            $response = $this->sendAndReceive($ws, 'ProtoOAOrderListReq', [
                'ctidTraderAccountId' => $accountId,
                'fromTimestamp' => $fromTimestamp,
                'toTimestamp' => $toTimestamp,
            ]);
            $orders = $response['order'] ?? [];

            $normalizer = $this->normalizer();
            $normalized = [];
            foreach ($orders as $order) {
                $row = $normalizer->normalizeCtraderClosedOrder($order);
                if ($row !== null) {
                    $normalized[] = $row;
                }
            }

            return ['orders' => $normalized, 'raw_count' => count($orders)];
        });
    }

    /**
     * Start of the deal window, in epoch ms.
     *
     * The stored cursor is a PROTOCOL value read back through strtotime(),
     * which resolves it in the server's timezone. An unusable one — unparseable
     * or landing after `now` — falls back to the default window rather than
     * being sent as-is: cTrader answers INCORRECT_BOUNDARIES to a window that
     * starts after it ends, the sync then fails, the cursor is never rewritten,
     * and the connection stays broken for good. This is the way out of that.
     */
    private function windowStart(?string $sinceCursor, int $toTimestamp): int
    {
        $default = (int) ((time() - 90 * 86400) * 1000);

        if ($sinceCursor === null || $sinceCursor === '') {
            return $default;
        }

        $parsed = strtotime($sinceCursor);
        if ($parsed === false) {
            return $default;
        }

        $from = (int) ($parsed * 1000);

        return $from > $toTimestamp ? $default : $from;
    }

    /**
     * Sync cursor for the next run, as UTC.
     *
     * It is taken from the raw `executionTimestamp` and NOT from the normalized
     * `closed_at`: since the journal stores local wall-clock time, reusing the
     * display value made a deal closed at 16:30 in Paris come back as 16:30
     * UTC — two hours into the future, and a window cTrader rejects outright.
     * The other three connectors all track raw API values for the same reason.
     *
     * Only closing deals count, matching what the run actually emitted: a
     * partial close held back for a still-open position must not push the
     * cursor past its own future closes.
     */
    private function cursorFrom(array $closedDeals): ?string
    {
        $latest = 0;
        foreach ($closedDeals as $deal) {
            if (!isset($deal['closePositionDetail'])) {
                continue;
            }
            $latest = max($latest, (int) ($deal['executionTimestamp'] ?? 0));
        }

        return $latest > 0 ? gmdate('Y-m-d H:i:s', intdiv($latest, 1000)) : null;
    }

    /**
     * Open a sync run: drop the memoised state so the same connector instance
     * can serve a second sync, and allow the session to be held open across the
     * five calls the run makes. Called by BrokerSyncService at the start of
     * every run — which is what makes the reuse opt-in.
     *
     * Dropping the reconcile cache matters as much as the symbol one: a
     * snapshot kept from the previous run would report positions that have
     * since closed.
     */
    public function resetSyncCache(): void
    {
        $this->closeSession();
        $this->symbolNameCache = [];
        $this->lotSizeCache = [];
        $this->pendingPartialExits = [];
        $this->requestCounts = [];
        $this->sessionReuse = true;
    }

    /**
     * What this run has spent so far, for the caller to journalise. Reset by
     * resetSyncCache(), so it is always the cost of one run rather than of the
     * process — the budget FTMO enforces is expressed per day, and the only way
     * to project one from the other is to know the per-run figure.
     *
     * Read it BEFORE closeSession(): the run is over once the socket is shut.
     *
     * @return array{total: int, by_type: array<string, int>}
     */
    public function getRequestCounts(): array
    {
        return [
            'total' => array_sum($this->requestCounts),
            'by_type' => $this->requestCounts,
        ];
    }

    /**
     * Close the run: hang up the shared socket and forget the snapshot.
     * BrokerSyncService calls this in a finally, so a run that blows up mid-way
     * never leaves a socket dangling.
     */
    public function closeSession(): void
    {
        $this->discardSession($this->session);
        $this->sessionReuse = false;
        $this->reconcileCache = [];
    }

    /**
     * The live snapshot, fetched at most once per account per run.
     *
     * `returnProtectionOrders` is always set, whoever asks first. Left unset,
     * cTrader COLLAPSES a staged exit plan into `position.takeProfit` — so a
     * position with five take profit levels reports one, and no filtering can
     * recover the rest because they were never sent. fetchDeals reconciles
     * before fetchOpenPositions does, so a cached request without the flag
     * would silently reintroduce that bug. Callers that don't want the
     * protection orders (fetchOpenOrders) already filter them out on
     * `closingOrder`.
     */
    private function reconcile(WsClient $ws, int $accountId): array
    {
        if (isset($this->reconcileCache[$accountId])) {
            return $this->reconcileCache[$accountId];
        }

        $response = $this->sendAndReceive($ws, 'ProtoOAReconcileReq', [
            'ctidTraderAccountId' => $accountId,
            'returnProtectionOrders' => true,
        ]);

        // Only memoise inside a run: outside one, two calls are two questions
        // about two different moments.
        if ($this->sessionReuse) {
            $this->reconcileCache[$accountId] = $response;
        }

        return $response;
    }

    /**
     * A live, authenticated socket for this account. Reuses the run's session
     * when there is one; otherwise connects and runs the two-step auth dance.
     *
     * Deliberately does NOT translate exceptions — fetchDeals lets a cTrader
     * error surface verbatim (the broker's own wording is what lands in
     * last_sync_error and is what makes a failure diagnosable), while
     * withAuthenticatedSession wraps them for the order path.
     */
    private function acquireSession(array $credentials): WsClient
    {
        $accountId = (int) ($credentials['ctid_trader_account_id'] ?? 0);

        if ($this->sessionReuse && $this->session !== null && $this->sessionAccountId === $accountId) {
            return $this->session;
        }

        // A different account on the same run: the old socket is authenticated
        // against someone else and cannot be reused.
        $this->discardSession($this->session);

        $ws = $this->connectWebSocket($credentials);

        // The socket has no owner yet: it is not the run's session until the
        // auth goes through, so neither fetchDeals' catch nor closeSession()
        // could reach it. A refused token would leak it for the life of the
        // process — and a refused token is the everyday failure here.
        try {
            $this->authenticate($ws, $credentials, $accountId);
        } catch (\Throwable $e) {
            try { $ws->close(); } catch (\Throwable) {}
            throw $e;
        }

        if ($this->sessionReuse) {
            $this->session = $ws;
            $this->sessionAccountId = $accountId;
        }

        return $ws;
    }

    private function authenticate(WsClient $ws, array $credentials, int $accountId): void
    {
        $this->sendAndReceive($ws, 'ProtoOAApplicationAuthReq', [
            'clientId' => $credentials['client_id'] ?? $this->config['client_id'] ?? '',
            'clientSecret' => $credentials['client_secret'] ?? $this->config['client_secret'] ?? '',
        ]);
        $this->sendAndReceive($ws, 'ProtoOAAccountAuthReq', [
            'ctidTraderAccountId' => $accountId,
            'accessToken' => $credentials['access_token'] ?? '',
        ]);
    }

    /**
     * Done with a socket. Kept open when it is the run's shared session, closed
     * otherwise — that single distinction is what turns five sessions into one
     * without changing anything for the callers outside a run.
     */
    private function releaseSession(?WsClient $ws): void
    {
        if ($ws === null || $ws === $this->session) {
            return;
        }
        try { $ws->close(); } catch (\Throwable) {}
    }

    /**
     * Throw the socket away. Used on error — a half-broken session must never
     * be handed to the next call of the run.
     */
    private function discardSession(?WsClient $ws): void
    {
        if ($ws !== null && $ws === $this->session) {
            $this->session = null;
            $this->sessionAccountId = null;
        }
        if ($ws !== null) {
            try { $ws->close(); } catch (\Throwable) {}
        }
    }

    /**
     * Split closing deals into those whose position has finished and those
     * whose position is still open on the broker.
     *
     * @param array<int, true> $openPositionIds
     * @return array{0: list<array>, 1: list<array>}
     */
    private function splitDealsByPositionState(array $deals, array $openPositionIds): array
    {
        $closed = [];
        $stillOpen = [];

        foreach ($deals as $deal) {
            if (isset($deal['closePositionDetail'], $openPositionIds[(int) ($deal['positionId'] ?? 0)])) {
                $stillOpen[] = $deal;
                continue;
            }
            $closed[] = $deal;
        }

        return [$closed, $stillOpen];
    }

    /**
     * Turn closing deals taken against still-open positions into the partial
     * exit rows BrokerOpenSyncService inserts, keyed by positionId.
     *
     * external_id is per DEAL (`ctrader_deal_<dealId>`), not per position: the
     * partial-exit dedup runs on it, and a position can be trimmed several
     * times. It also has to stay distinct from the position's own
     * `ctrader_<positionId>`.
     *
     * @return array<int, list<array>>
     */
    private function buildPartialExits(array $deals): array
    {
        $normalizer = $this->normalizer();
        $exits = [];

        foreach ($deals as $deal) {
            $row = $normalizer->normalizeCtraderDeal($deal);
            if ($row === null) {
                continue;
            }
            $exits[(int) $deal['positionId']][] = [
                'exit_price' => $row['exit_price'],
                'size' => $row['size'],
                'pnl' => $row['pnl'],
                'closed_at' => $row['closed_at'],
                'external_id' => 'ctrader_deal_' . ($deal['dealId'] ?? $deal['positionId']),
            ];
        }

        return $exits;
    }

    /**
     * Staged take profit levels per positionId, from the reconcile order list.
     *
     * cTrader places partial take profits server-side as LIMIT orders bound to
     * the position (`closingOrder` true, `positionId` set). They are the only
     * record of a staged exit plan — `ProtoOAPosition.takeProfit` holds a
     * single level — and each carries the volume that comes off at that step,
     * which the position's own level cannot express.
     *
     * The platform stages up to five levels per position, each with its own
     * quantity, and the public documentation never states how they come out on
     * the Open API. Reading only LIMIT closing orders brought back nothing on a
     * real account, so the collection is deliberately broad: an order counts as
     * a level when it exposes a take profit price, whether as a LIMIT trigger
     * (`limitPrice`) or as a protective order carrying `takeProfit`. A
     * protective order holding only a stop loss is not an objective and stays
     * out. Levels are deduplicated by price — the position's own `takeProfit`
     * mirrors one of them, and two orders may report the same level.
     *
     * @return array<int, list<array{price: float, volume: int}>>
     */
    private function collectStagedTakeProfits(array $orders): array
    {
        $staged = [];
        $seen = [];

        foreach ($orders as $order) {
            // positionId is the link that matters. Requiring `closingOrder` too
            // was a guess that came back empty on a real account: the flag is
            // documented for orders the USER places to close part of a
            // position, and nothing promises the platform sets it on the
            // protections returned by returnProtectionOrders.
            $positionId = (int) ($order['positionId'] ?? 0);
            if ($positionId === 0) {
                continue;
            }

            $price = $this->takeProfitLevelOf($order);
            if ($price === null) {
                continue;
            }

            $key = $positionId . ':' . (string) $price;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $staged[$positionId][] = [
                'price' => $price,
                'volume' => (int) ($order['tradeData']['volume'] ?? 0),
            ];
        }

        return $staged;
    }

    /**
     * Log the SHAPE of a position's closing orders when it advertises a take
     * profit and yet no level could be read from them.
     *
     * The staged-level representation is not documented, so this is the only
     * way to learn it without asking the user to resync blind. It records which
     * order types are bound to the position and which price fields they carry —
     * never a price, a volume or an identifier, so the line stays a structural
     * hint and nothing more. Silent in the nominal case.
     */
    private function reportUnresolvedTakeProfits(array $position, array $orders): void
    {
        if (!empty($position['takeProfitOrders']) || empty($position['takeProfit'])) {
            return;
        }

        $positionId = (int) ($position['positionId'] ?? 0);
        $boundToPosition = 0;
        $shapes = [];

        // Every order in the payload, not just those bound to this position:
        // three very different failures look alike from the outside, and only
        // the counts tell them apart — an empty payload means the request flag
        // brought nothing back, orders present but none bound means they arrive
        // without a positionId, and orders bound but no level means the price
        // sits in a field we are not reading. The shapes then say which.
        foreach ($orders as $order) {
            if ((int) ($order['positionId'] ?? 0) === $positionId) {
                $boundToPosition++;
            }
            $shapes[] = implode('|', [
                'type=' . (string) ($order['orderType'] ?? '?'),
                'boundToPosition=' . ((int) ($order['positionId'] ?? 0) === $positionId ? '1' : '0'),
                'hasPositionId=' . (isset($order['positionId']) ? '1' : '0'),
                'closing=' . (empty($order['closingOrder']) ? '0' : '1'),
                'limitPrice=' . (isset($order['limitPrice']) ? '1' : '0'),
                'stopPrice=' . (isset($order['stopPrice']) ? '1' : '0'),
                'takeProfit=' . (isset($order['takeProfit']) ? '1' : '0'),
                'stopLoss=' . (isset($order['stopLoss']) ? '1' : '0'),
            ]);
        }

        BrokerLogger::failure('ctrader', 'take_profit_levels_unresolved', [
            'orders_in_payload' => count($orders),
            'orders_bound_to_position' => $boundToPosition,
            'order_shapes' => array_slice(array_values(array_unique($shapes)), 0, 10),
        ]);
    }

    /**
     * The take profit price an order defines, or null when it defines none.
     * A LIMIT closing order triggers at `limitPrice`; a protective order states
     * its objective in `takeProfit`, and holds only `stopLoss` when it is
     * purely a stop.
     */
    private function takeProfitLevelOf(array $order): ?float
    {
        $price = $this->isLimitOrder($order['orderType'] ?? null)
            ? ($order['limitPrice'] ?? null)
            : ($order['takeProfit'] ?? null);

        return $price !== null && (float) $price > 0 ? (float) $price : null;
    }

    /**
     * cTrader may serialize orderType as its enum name or its integer code
     * (LIMIT = 2), same tolerance as everywhere else in this connector.
     */
    private function isLimitOrder(mixed $orderType): bool
    {
        if (is_int($orderType) || (is_string($orderType) && ctype_digit($orderType))) {
            return (int) $orderType === 2;
        }

        return strtoupper(str_replace('ORDER_TYPE_', '', (string) $orderType)) === 'LIMIT';
    }

    /**
     * Collect the distinct, non-null symbolId values from a set of positions
     * or orders (both carry symbolId under tradeData) so we can resolve their
     * names in a single ProtoOASymbolByIdReq.
     *
     * @return int[]
     */
    private function collectSymbolIds(array $entities): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn($e) => $e['tradeData']['symbolId'] ?? null, $entities),
            fn($id) => $id !== null,
        )));
    }

    /**
     * List the trading accounts reachable with an access token.
     *
     * Exists because the connect form used to ask the user to type
     * `ctidTraderAccountId` by hand — a number the cTrader platform never
     * displays. What the platform shows is `traderLogin`, a *different* number,
     * so entering it produced "account not found". This returns both, plus the
     * live/demo flag, so the UI can show the login the user recognises while
     * storing the id the API actually authenticates with, and derive the server
     * instead of asking.
     *
     * Listing the accounts is keyed by access token, not by account, so it runs
     * under application auth alone — before we know which account to
     * authenticate. Each entry is then enriched per account, which does need
     * account auth: see enrichAccounts() for the balance, the currency and the
     * archived flag, and scalarDetails() for the raw passthrough.
     *
     * @return list<array{ctid_trader_account_id: int, trader_login: string, is_live: bool, broker_name: ?string, balance: ?float, currency: ?string, is_disabled: bool, disabled_reason: ?string, details: array<string, string|int|float|bool>}>
     */
    public function fetchAccounts(array $credentials): array
    {
        $ws = $this->connectWebSocket($credentials);

        try {
            $this->sendAndReceive($ws, 'ProtoOAApplicationAuthReq', [
                'clientId' => $credentials['client_id'] ?? $this->config['client_id'] ?? '',
                'clientSecret' => $credentials['client_secret'] ?? $this->config['client_secret'] ?? '',
            ]);

            $response = $this->sendAndReceive($ws, 'ProtoOAGetAccountListByAccessTokenReq', [
                'accessToken' => $credentials['access_token'] ?? '',
            ]);

            $accounts = [];
            foreach ($response['ctidTraderAccount'] ?? [] as $account) {
                // An entry without an id is unusable — it is precisely the value we
                // need to store, so skip rather than emit a broken option.
                if (!isset($account['ctidTraderAccountId'])) {
                    continue;
                }
                $accounts[] = [
                    'ctid_trader_account_id' => (int) $account['ctidTraderAccountId'],
                    // Kept as a string: logins can exceed PHP's int range on some
                    // brokers and it is only ever displayed, never computed with.
                    'trader_login' => (string) ($account['traderLogin'] ?? ''),
                    'is_live' => (bool) ($account['isLive'] ?? false),
                    'broker_name' => isset($account['brokerTitleShort'])
                        ? (string) $account['brokerTitleShort']
                        : null,
                    // Filled in by enrichAccounts() below, when reachable.
                    'balance' => null,
                    'currency' => null,
                    'is_disabled' => false,
                    'disabled_reason' => null,
                    // Everything else, verbatim. A user with many accounts could
                    // not tell which were their live FTMO ones — the list also
                    // returns archived accounts (picking one fails with
                    // RET_ACCOUNT_DISABLED), and whatever distinguishes them was
                    // being dropped here. Passing the raw entry through means the
                    // UI can surface it without another round-trip, and without us
                    // guessing which fields this broker actually sends.
                    'details' => $this->scalarDetails($account),
                ];
            }

            // Same socket, still open: enrichment is a series of extra requests,
            // not a second connection.
            $accounts = $this->enrichAccounts($ws, $accounts, (string) ($credentials['access_token'] ?? ''));

            try { $ws->close(); } catch (\Throwable) {}
        } catch (\Throwable $e) {
            try { $ws->close(); } catch (\Throwable) {}
            throw $e;
        }

        return $accounts;
    }

    /**
     * Add balance, deposit currency and reachability to each listed account.
     *
     * The account list is not enough to tell accounts apart: at a prop firm
     * they share one `brokerTitleShort` and differ only by size, so the picker
     * needs "FTMO — 80 000 € — 1234567". Balance and `depositAssetId` live in
     * ProtoOATrader, which is one account auth away.
     *
     * That auth doubles as the archived-account test. Nothing in
     * ProtoOACtidTraderAccount marks an archived account, but authenticating
     * one fails with RET_ACCOUNT_DISABLED — so the accounts that cannot be
     * connected identify themselves here, instead of the user discovering it
     * after saving the connection.
     *
     * Best-effort throughout: one account's failure never costs the others
     * their entry, and never costs the list its own result.
     */
    private function enrichAccounts(WsClient $ws, array $accounts, string $accessToken): array
    {
        // Asset ids are broker-scoped, so accounts at the same broker share one
        // list — worth caching, as it is a third of the round trips otherwise.
        $assetNames = [];

        foreach ($accounts as $i => $account) {
            // A token with hundreds of accounts would hold the request open for
            // minutes. Past the cap the entries stay listed, just unenriched.
            if ($i >= self::MAX_ENRICHED_ACCOUNTS) {
                break;
            }

            $accountId = $account['ctid_trader_account_id'];

            try {
                $this->sendAndReceive($ws, 'ProtoOAAccountAuthReq', [
                    'ctidTraderAccountId' => $accountId,
                    'accessToken' => $accessToken,
                ]);
            } catch (\Throwable $e) {
                if ($code = $this->accountRefusalCode($e)) {
                    $accounts[$i]['is_disabled'] = true;
                    $accounts[$i]['disabled_reason'] = $code;
                }
                continue;
            }

            try {
                $trader = $this->sendAndReceive($ws, 'ProtoOATraderReq', [
                    'ctidTraderAccountId' => $accountId,
                ])['trader'] ?? [];
            } catch (\Throwable) {
                continue;
            }

            $accounts[$i]['details'] = array_merge(
                $accounts[$i]['details'],
                $this->scalarDetails($trader),
            );

            if (isset($trader['balance'])) {
                // Money is an integer scaled by moneyDigits (see fetchBalance).
                $moneyDigits = (int) ($trader['moneyDigits'] ?? 2);
                $accounts[$i]['balance'] = ((float) $trader['balance']) / (10 ** $moneyDigits);
            }

            if (!isset($trader['depositAssetId'])) {
                continue;
            }

            // No broker name to key the cache on: treat the account as its own
            // broker rather than risk borrowing another's asset ids.
            $brokerKey = $account['broker_name'] ?? "#{$accountId}";
            if (!array_key_exists($brokerKey, $assetNames)) {
                $assetNames[$brokerKey] = $this->fetchAssetNames($ws, $accountId);
            }
            $accounts[$i]['currency'] = $assetNames[$brokerKey][(int) $trader['depositAssetId']] ?? null;
        }

        return $accounts;
    }

    /**
     * Map assetId → asset name ("EUR") for one account's broker.
     *
     * ProtoOATrader gives the deposit currency as a numeric `depositAssetId`;
     * only this list turns it into something a user can read. Best-effort: a
     * failure costs the currency, not the account entry.
     *
     * @return array<int, string>
     */
    private function fetchAssetNames(WsClient $ws, int $accountId): array
    {
        try {
            $response = $this->sendAndReceive($ws, 'ProtoOAAssetListReq', [
                'ctidTraderAccountId' => $accountId,
            ]);
        } catch (\Throwable) {
            return [];
        }

        $names = [];
        foreach ($response['asset'] ?? [] as $asset) {
            if (isset($asset['assetId'], $asset['name'])) {
                $names[(int) $asset['assetId']] = (string) $asset['name'];
            }
        }

        return $names;
    }

    /**
     * The broker's error code when it says *this account* cannot be used, null
     * otherwise.
     *
     * Two filters, and both matter. sendAndReceive() renders a ProtoOAErrorRes
     * as "cTrader API error: CODE - description" and leaves every other failure
     * (closed socket, malformed frame) with its own wording, so a transport
     * glitch is never reported as an archived account.
     *
     * Then the code has to be account-scoped. A malformed request comes back as
     * INVALID_REQUEST, an app-level problem as CH_CLIENT_AUTH_FAILURE — neither
     * says anything about the account, yet both arrive here once per account
     * and would flag every single one. Since a disabled account is hidden from
     * the picker, that turns a request bug into "all my accounts vanished".
     *
     * Hence matching on ACCOUNT in the code (RET_ACCOUNT_DISABLED,
     * CH_CTID_TRADER_ACCOUNT_NOT_FOUND, …). It is a heuristic, deliberately
     * biased: an unrecognised code leaves the account listed, so the worst case
     * is the user picking a dud and being told at save time — the old
     * behaviour — rather than losing a working account with no explanation.
     */
    private function accountRefusalCode(\Throwable $e): ?string
    {
        if (!preg_match('/^cTrader API error: ([A-Z0-9_]+)/', $e->getMessage(), $m)) {
            return null;
        }

        return str_contains($m[1], 'ACCOUNT') ? $m[1] : null;
    }

    /**
     * Flatten one raw account entry to its scalar fields, for display.
     *
     * Nested structures are skipped rather than rendered: the point is a
     * readable "what is this account" panel, not a JSON dump.
     *
     * Safe to show as-is: `ProtoOACtidTraderAccount` documents six fields —
     * ctidTraderAccountId, isLive, traderLogin, lastClosingDealTimestamp,
     * lastBalanceUpdateTimestamp, brokerTitleShort — none of which is a secret,
     * a credential or an amount. This is a pass-through rather than an
     * allow-list on purpose: an unknown field is the thing we most want to see
     * in the panel, since none of the six marks an account as archived and we
     * are still looking for what does.
     *
     * enrichAccounts() also runs ProtoOATrader through here, which is a wider
     * surface. Still the user's own data, shown only to them and never written
     * down — but note `swapFree` means "Shariah compliant", i.e. an inference
     * about religion. Harmless while this stays in-flight; do not log the
     * discovery response without revisiting that.
     *
     * @return array<string, string|int|float|bool>
     */
    private function scalarDetails(array $account): array
    {
        $details = [];
        foreach ($account as $key => $value) {
            if (is_scalar($value)) {
                $details[$key] = $value;
            }
        }
        return $details;
    }

    public function refreshCredentials(array $credentials): array
    {
        if (empty($credentials['refresh_token'])) {
            return $credentials;
        }

        $http = $this->httpClient ?? new HttpClient();

        // The cTrader OAuth app credentials are stored per-connection (the
        // user enters them in the connect dialog), so prefer the credentials'
        // client_id/secret and fall back to config. oauth_token_url falls back
        // to the known cTrader endpoint when not configured.
        $tokenUrl = $this->config['oauth_token_url'] ?? 'https://openapi.ctrader.com/apps/token';
        $response = $http->get($tokenUrl, [
            'query' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $credentials['refresh_token'],
                'client_id' => $credentials['client_id'] ?? $this->config['client_id'] ?? '',
                'client_secret' => $credentials['client_secret'] ?? $this->config['client_secret'] ?? '',
            ],
        ]);

        $tokens = json_decode($response->getBody()->getContents(), true);

        if (!isset($tokens['accessToken'])) {
            throw new \RuntimeException('cTrader token refresh failed');
        }

        $credentials['access_token'] = $tokens['accessToken'];
        if (isset($tokens['refreshToken'])) {
            $credentials['refresh_token'] = $tokens['refreshToken'];
        }

        return $credentials;
    }

    public function testConnection(array $credentials): bool
    {
        $this->clearTestError();
        try {
            $ws = $this->connectWebSocket($credentials);
            $this->sendAndReceive($ws, 'ProtoOAApplicationAuthReq', [
                'clientId' => $credentials['client_id'] ?? $this->config['client_id'] ?? '',
                'clientSecret' => $credentials['client_secret'] ?? $this->config['client_secret'] ?? '',
            ]);
            $this->sendAndReceive($ws, 'ProtoOAAccountAuthReq', [
                'ctidTraderAccountId' => $credentials['ctid_trader_account_id'],
                'accessToken' => $credentials['access_token'],
            ]);
            $ws->close();
            return true;
        } catch (\Throwable $e) {
            // The message carries the broker's own wording, e.g.
            // "cTrader API error: CH_CLIENT_AUTH_FAILURE - wrong clientSecret",
            // which tells the user exactly which credential to fix.
            return $this->failedTest($e);
        }
    }

    /**
     * Account balance via ProtoOATraderReq → trader. cTrader expresses money
     * as an integer scaled by moneyDigits (e.g. balance 10053099944 with
     * moneyDigits 8 → 100.53099944). Best-effort: any failure returns null so
     * the sync leaves the previous balance alone.
     *
     * Also resolves the deposit currency, exposed through getBalanceCurrency()
     * — see there for why the figure alone was not enough.
     */
    public function fetchBalance(array $credentials): ?float
    {
        $this->lastBalanceCurrency = null;

        if (empty($credentials['ctid_trader_account_id']) || empty($credentials['access_token'])) {
            return null;
        }

        try {
            $result = $this->withAuthenticatedSession($credentials, function (WsClient $ws, int $accountId) {
                $response = $this->sendAndReceive($ws, 'ProtoOATraderReq', [
                    'ctidTraderAccountId' => $accountId,
                ]);
                $trader = $response['trader'] ?? [];
                if (!isset($trader['balance'])) {
                    return ['balance' => null, 'currency' => null];
                }
                $moneyDigits = (int) ($trader['moneyDigits'] ?? 2);

                // depositAssetId is a number; only the asset list names it.
                $currency = null;
                if (isset($trader['depositAssetId'])) {
                    $currency = $this->fetchAssetNames($ws, $accountId)[(int) $trader['depositAssetId']] ?? null;
                }

                return [
                    'balance' => ((float) $trader['balance']) / (10 ** $moneyDigits),
                    'currency' => $currency,
                ];
            });

            $this->lastBalanceCurrency = $result['currency'];

            return $result['balance'];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Currency the last fetchBalance() figure is denominated in, null when no
     * balance was read or the asset list could not be resolved.
     *
     * BrokerSyncService looks this method up by name and stores the result
     * next to the balance, so the UI can flag an account whose own currency
     * differs from the one its broker actually reports. Only BingX implemented
     * it, so a cTrader sync always recorded a null currency — an account set
     * up in EUR against a USD broker account had no way to show the mismatch.
     */
    public function getBalanceCurrency(): ?string
    {
        return $this->lastBalanceCurrency;
    }

    public function placeOrder(array $credentials, array $order): array
    {
        $direction = strtoupper($order['direction'] ?? '');
        $orderType = strtoupper($order['order_type'] ?? 'MARKET');
        if (!in_array($direction, ['BUY', 'SELL'], true)) {
            throw new \App\Exceptions\BrokerOrderException(
                "Unsupported direction {$direction}",
                'UNSUPPORTED_ORDER',
            );
        }
        $orderTypeProto = match ($orderType) {
            'MARKET' => 'MARKET',
            'LIMIT' => 'LIMIT',
            'STOP' => 'STOP',
            default => throw new \App\Exceptions\BrokerOrderException(
                "Unsupported order_type {$orderType}",
                'UNSUPPORTED_ORDER',
            ),
        };

        return $this->withAuthenticatedSession($credentials, function (WsClient $ws, int $accountId) use ($order, $direction, $orderTypeProto) {
            $symbolId = $this->resolveSymbolId($ws, $accountId, (string) $order['symbol']);

            // cTrader expresses volume in 1/100 lots (one lot = 100k for FX,
            // 100 for index CFDs etc.). The caller passes a fractional lot
            // ("size = 0.10") so we multiply by 100 and floor to int.
            $volume = (int) floor(((float) $order['size']) * 100);
            if ($volume <= 0) {
                throw new \App\Exceptions\BrokerOrderException(
                    'cTrader requires a positive integer volume (100 = 0.01 lot)',
                    'INVALID_VOLUME',
                );
            }

            $payload = [
                'ctidTraderAccountId' => $accountId,
                'symbolId' => $symbolId,
                'orderType' => $orderTypeProto,
                'tradeSide' => $direction,
                'volume' => $volume,
            ];

            if ($orderTypeProto !== 'MARKET' && !empty($order['entry_price'])) {
                $payload[$orderTypeProto === 'LIMIT' ? 'limitPrice' : 'stopPrice'] = (float) $order['entry_price'];
            }
            if (!empty($order['sl_price'])) {
                $payload['stopLoss'] = (float) $order['sl_price'];
            }
            if (!empty($order['tp_prices'][0])) {
                $payload['takeProfit'] = (float) $order['tp_prices'][0];
            }
            if (!empty($order['client_order_id'])) {
                $payload['comment'] = mb_substr((string) $order['client_order_id'], 0, 256);
            }

            $response = $this->sendAndReceive($ws, 'ProtoOANewOrderReq', $payload);

            // cTrader acknowledges with ProtoOAExecutionEvent carrying an order
            // sub-object. The orderId lives at executionEvent.order.orderId.
            $event = $response['order'] ?? $response['executionEvent']['order'] ?? null;
            $orderId = $event['orderId'] ?? $response['orderId'] ?? null;
            if ($orderId === null) {
                throw new \App\Exceptions\BrokerOrderException(
                    'cTrader did not return an orderId in ProtoOAExecutionEvent',
                    'NO_ORDER_ID',
                    is_array($response) ? $response : [],
                );
            }

            return [
                'external_order_id' => (string) $orderId,
                'status' => $event['orderStatus'] ?? null,
                'raw' => $response,
            ];
        });
    }

    public function cancelOrder(array $credentials, string $externalOrderId): array
    {
        return $this->withAuthenticatedSession($credentials, function (WsClient $ws, int $accountId) use ($externalOrderId) {
            $response = $this->sendAndReceive($ws, 'ProtoOACancelOrderReq', [
                'ctidTraderAccountId' => $accountId,
                'orderId' => (int) $externalOrderId,
            ]);
            $event = $response['order'] ?? $response['executionEvent']['order'] ?? [];
            return ['status' => $event['orderStatus'] ?? null, 'raw' => $response];
        });
    }

    public function closePosition(array $credentials, string $externalPositionId, ?float $sizeOverride = null): array
    {
        return $this->withAuthenticatedSession($credentials, function (WsClient $ws, int $accountId) use ($externalPositionId, $sizeOverride) {
            $payload = [
                'ctidTraderAccountId' => $accountId,
                'positionId' => (int) $externalPositionId,
            ];
            // 0 = full close in ProtoOAClosePositionReq semantics — but we
            // forward an explicit volume when the caller wants partial close.
            if ($sizeOverride !== null) {
                $partial = (int) floor($sizeOverride * 100);
                if ($partial <= 0) {
                    throw new \App\Exceptions\BrokerOrderException(
                        'cTrader partial close requires a positive volume',
                        'INVALID_VOLUME',
                    );
                }
                $payload['volume'] = $partial;
            }

            $response = $this->sendAndReceive($ws, 'ProtoOAClosePositionReq', $payload);
            // ProtoOAExecutionEvent carries executionType at the payload root.
            return ['status' => $response['executionType'] ?? $response['executionEvent']['executionType'] ?? null, 'raw' => $response];
        });
    }

    /**
     * Open a WebSocket, run the two-step auth dance (application then account)
     * and pass the live session to the caller. Closes the socket on success
     * AND on any throwable, including BrokerOrderException. Centralises the
     * session boilerplate so the three outbound order methods stay compact.
     */
    private function withAuthenticatedSession(array $credentials, callable $callback): array
    {
        $accountId = $credentials['ctid_trader_account_id'] ?? null;
        $accessToken = $credentials['access_token'] ?? null;
        if (!$accountId || !$accessToken) {
            throw new \App\Exceptions\BrokerOrderException(
                'cTrader credentials missing ctid_trader_account_id/access_token',
                'INVALID_CREDENTIALS',
            );
        }

        // The connect and the auth are reported differently — a socket that
        // never opened is a transport problem, refused credentials are the
        // broker rejecting us — so they cannot be folded into one try.
        $reused = $this->sessionReuse
            && $this->session !== null
            && $this->sessionAccountId === (int) $accountId;

        if ($reused) {
            $ws = $this->session;
        } else {
            $this->discardSession($this->session);
            try {
                $ws = $this->connectWebSocket($credentials);
            } catch (\Throwable $e) {
                BrokerLogger::failure('ctrader', 'ws_connect_failed', [
                    'account_id' => (int) $accountId,
                    'msg' => $e->getMessage(),
                ]);
                throw new \App\Exceptions\BrokerOrderException(
                    'cTrader WebSocket connect failed: ' . $e->getMessage(),
                    'TRANSPORT_ERROR',
                    [],
                    $e,
                );
            }
        }

        try {
            if (!$reused) {
                $this->authenticate($ws, $credentials, (int) $accountId);
                if ($this->sessionReuse) {
                    $this->session = $ws;
                    $this->sessionAccountId = (int) $accountId;
                }
            }

            $result = $callback($ws, (int) $accountId);
            $this->releaseSession($ws);
            return $result;
        } catch (\App\Exceptions\BrokerOrderException $e) {
            $this->discardSession($ws);
            throw $e;
        } catch (\Throwable $e) {
            $this->discardSession($ws);
            BrokerLogger::failure('ctrader', 'request_failed', [
                'account_id' => (int) $accountId,
                'msg' => $e->getMessage(),
            ]);
            throw new \App\Exceptions\BrokerOrderException(
                'cTrader request failed: ' . $e->getMessage(),
                'BROKER_REJECTED',
                [],
                $e,
            );
        }
    }

    private function resolveSymbolId(WsClient $ws, int $accountId, string $symbolName): int
    {
        // ProtoOASymbolsListReq returns every tradable symbol on the account
        // with its numeric id. We cache nothing here — placeOrder is rare
        // enough that the extra round-trip is acceptable, and it keeps the
        // connector stateless.
        $response = $this->sendAndReceive($ws, 'ProtoOASymbolsListReq', [
            'ctidTraderAccountId' => $accountId,
        ]);
        $target = strtoupper($symbolName);
        foreach ($response['symbol'] ?? [] as $symbol) {
            if (strtoupper((string) ($symbol['symbolName'] ?? '')) === $target) {
                return (int) $symbol['symbolId'];
            }
        }
        throw new \App\Exceptions\BrokerOrderException(
            "cTrader symbol '{$symbolName}' not found on account {$accountId}",
            'UNKNOWN_SYMBOL',
        );
    }

    /**
     * Normalize raw cTrader deals into import format.
     */
    public function normalizeDeals(array $rawDeals): array
    {
        $normalizer = $this->normalizer();
        $deals = [];

        foreach ($rawDeals as $deal) {
            $normalized = $normalizer->normalizeCtraderDeal($deal);
            if ($normalized !== null) {
                $deals[] = $normalized;
            }
        }

        return $deals;
    }

    /**
     * Build a JSON message for the cTrader Open API. Wire format:
     * {clientMsgId, payloadType:<int>, payload:{…}} — numeric payloadType and
     * nested payload, per https://help.ctrader.com/open-api/sending-receiving-json/.
     * The (object) cast keeps an empty payload serialized as `{}`, not `[]`.
     */
    public function buildMessage(string $payloadType, array $payload = []): string
    {
        return json_encode([
            'clientMsgId' => (string) (++$this->msgCounter),
            'payloadType' => self::PAYLOAD_TYPES[$payloadType],
            'payload' => (object) $payload,
        ]);
    }

    private function connectWebSocket(array $credentials = []): WsClient
    {
        if ($this->wsClient) {
            return $this->wsClient;
        }

        return new WsClient($this->resolveWsUrl($credentials), [
            'timeout' => 30,
        ]);
    }

    /**
     * Endpoint for a given connection. A cTrader trading account lives on
     * exactly one of the two servers, so the host follows the connection's own
     * `environment` credential rather than a process-wide env var — otherwise
     * one user's demo account forces every other user onto the demo host.
     *
     * Connections created before the selector shipped carry no `environment`
     * key and keep resolving through the configured host (CTRADER_WS_HOST),
     * which preserves their existing behaviour exactly.
     */
    public function resolveWsUrl(array $credentials = []): string
    {
        $environment = CtraderEnvironment::tryFrom(
            strtoupper((string) ($credentials['environment'] ?? ''))
        );

        $host = $environment?->wsHost()
            ?? $this->config['ws_host']
            ?? CtraderEnvironment::LIVE->wsHost();
        $port = $this->config['ws_port'] ?? 5036;

        return "wss://{$host}:{$port}";
    }

    private function sendAndReceive(WsClient $ws, string $payloadType, array $payload = []): array
    {
        // Counted here rather than at the call sites: this is the only place a
        // request reaches the socket, so the tally cannot drift from reality as
        // the connector grows new calls.
        $this->requestCounts[$payloadType] = ($this->requestCounts[$payloadType] ?? 0) + 1;

        $ws->text($this->buildMessage($payloadType, $payload));

        // The Open API is asynchronous: heartbeats (payloadType 51) and other
        // unsolicited events can arrive interleaved. Keep reading until a real
        // response frame arrives rather than mistaking a heartbeat for it.
        while (true) {
            $response = $ws->receive();

            $decoded = json_decode($response, true);
            if (!$decoded) {
                throw new \RuntimeException("Invalid response from cTrader API for $payloadType");
            }

            $type = $decoded['payloadType'] ?? null;
            if ($type === self::HEARTBEAT_EVENT) {
                continue;
            }

            // Every ProtoOA frame nests its fields under `payload`.
            $inner = $decoded['payload'] ?? [];

            // Error detection MUST compare the numeric code — the old string
            // comparison never matched, so real errors were swallowed.
            if ($type === self::PAYLOAD_TYPES['ProtoOAErrorRes']) {
                $errorCode = $inner['errorCode'] ?? 'UNKNOWN';
                $description = $inner['description'] ?? '';
                throw new \RuntimeException("cTrader API error: $errorCode - $description");
            }

            return $inner;
        }
    }

    /**
     * Resolve a set of symbol ids to the two things the journal needs: the
     * display name and the lot size used to scale volumes.
     *
     * It takes two messages because cTrader splits the symbol across two
     * types. ProtoOASymbolByIdRes returns ProtoOASymbol, which has lotSize,
     * digits, swap rates and schedules — but NO symbolName field at all. The
     * name lives only on ProtoOALightSymbol, returned by ProtoOASymbolsListReq.
     * Reading `symbolName` off the by-id response therefore always missed, and
     * every synced trade was labelled with the `SYM_<id>` fallback.
     *
     * @param int[] $symbolIds
     * @return array<int, array{name: string, lot_size: int}>
     */
    private function resolveSymbols(WsClient $ws, int $accountId, array $symbolIds): array
    {
        if (empty($symbolIds)) {
            return [];
        }

        $names = $this->lightSymbolNames($ws, $accountId);
        $lotSizes = $this->lotSizeCache[$accountId] ?? [];

        // Only ask about ids we have never resolved. A run resolves symbols
        // three times over (deals, positions, orders), almost always on the
        // same handful of instruments, and each round-trip counts against the
        // broker's request budget.
        $missing = array_values(array_filter(
            array_map('intval', $symbolIds),
            fn(int $id) => !array_key_exists($id, $lotSizes),
        ));

        if (!empty($missing)) {
            // array_values() above is load-bearing: cTrader reads symbolId as a
            // repeated int64 and a gappy array serialises as an object.
            $response = $this->sendAndReceive($ws, 'ProtoOASymbolByIdReq', [
                'ctidTraderAccountId' => $accountId,
                'symbolId' => array_values(array_unique($missing)),
            ]);
            foreach ($response['symbol'] ?? [] as $symbol) {
                if (isset($symbol['symbolId'])) {
                    $lotSizes[(int) $symbol['symbolId']] = (int) ($symbol['lotSize'] ?? 0);
                }
            }

            // ProtoOASymbolsListReq leaves out archived symbols unless asked, so
            // an instrument the broker has retired is absent from the light list
            // and could never be named — one symbol stuck at SYM_<id> while
            // every other one on the account resolved. The by-id response we
            // just made returns archivedSymbol[] alongside symbol[]; ProtoOA-
            // ArchivedSymbol spells it `name`, not `symbolName`.
            foreach ($response['archivedSymbol'] ?? [] as $archived) {
                if (isset($archived['symbolId'], $archived['name'])) {
                    $names[(int) $archived['symbolId']] ??= (string) $archived['name'];
                    $lotSizes[(int) $archived['symbolId']] ??= 0;
                }
            }

            // Remember the misses too: an id cTrader does not answer for must
            // not be re-asked on every resolution of the run.
            foreach ($missing as $id) {
                $lotSizes[$id] ??= 0;
            }

            $this->symbolNameCache[$accountId] = $names;
            $this->lotSizeCache[$accountId] = $lotSizes;
        }

        $map = [];
        foreach ($symbolIds as $symbolId) {
            $symbolId = (int) $symbolId;
            $map[$symbolId] = [
                'name' => $names[$symbolId] ?? ('SYM_' . $symbolId),
                'lot_size' => $lotSizes[$symbolId] ?? 0,
            ];
        }

        return $map;
    }

    /**
     * symbolId → symbolName for every symbol on the account. Memoised for the
     * whole sync run: the list covers the broker's entire universe, and a sync
     * resolves symbols three times over (deals, positions, orders).
     *
     * @return array<int, string>
     */
    private function lightSymbolNames(WsClient $ws, int $accountId): array
    {
        if (isset($this->symbolNameCache[$accountId])) {
            return $this->symbolNameCache[$accountId];
        }

        $response = $this->sendAndReceive($ws, 'ProtoOASymbolsListReq', [
            'ctidTraderAccountId' => $accountId,
        ]);

        $names = [];
        foreach ($response['symbol'] ?? [] as $symbol) {
            if (isset($symbol['symbolId'], $symbol['symbolName'])) {
                $names[(int) $symbol['symbolId']] = (string) $symbol['symbolName'];
            }
        }

        return $this->symbolNameCache[$accountId] = $names;
    }

    /**
     * Stamp a position/order entity with the resolved symbol name and lot size
     * so the normalizer can label it and scale its volume.
     */
    private function withSymbol(array $entity, array $symbolMap): array
    {
        $symbolId = (int) ($entity['tradeData']['symbolId'] ?? 0);
        $entity['symbolName'] = $symbolMap[$symbolId]['name'] ?? ('UNKNOWN_' . $symbolId);
        $entity['lotSize'] = $symbolMap[$symbolId]['lot_size'] ?? 0;

        return $entity;
    }

    /** Order modification not implemented for cTrader yet (docs/70 v1: BingX only). */
    public function modifyOrder(array $credentials, array $modification): array
    {
        throw new \App\Exceptions\BrokerOrderException(
            'modifyOrder not implemented for cTrader',
            'NOT_IMPLEMENTED',
        );
    }
}
