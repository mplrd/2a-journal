<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\SyncLogRepository;
use App\Services\Broker\BrokerConnectionService;
use App\Services\Broker\BrokerSyncService;

class BrokerSyncController extends Controller
{
    public function __construct(
        private BrokerSyncService $syncService,
        private BrokerConnectionService $connectionService,
        private SyncLogRepository $syncLogRepo,
    ) {}

    /**
     * Create a broker connection (credentials provided in body).
     */
    public function createConnection(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $body = $request->getBody();

        $result = $this->connectionService->createConnection(
            $userId,
            (int) ($body['account_id'] ?? 0),
            (string) ($body['provider'] ?? ''),
            $body,
        );

        return $this->jsonSuccess($result);
    }

    /**
     * Reconfigure an existing connection's credentials in place. Fields left
     * blank keep their stored value, so the user can fix a single rotated
     * secret without re-entering everything — and without deleting the
     * connection, which would drop its sync cursor and log history.
     */
    public function updateConnection(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $connectionId = (int) $request->getRouteParam('id');

        $result = $this->connectionService->updateCredentials(
            $connectionId,
            $userId,
            $request->getBody(),
        );

        return $this->jsonSuccess($result);
    }

    /**
     * List the cTrader accounts reachable with the supplied app credentials, so
     * the user picks one instead of typing ctidTraderAccountId by hand.
     */
    public function ctraderAccounts(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');

        return $this->jsonSuccess(
            $this->connectionService->discoverCtraderAccounts($userId, $request->getBody()),
        );
    }

    /**
     * Get connection for an account, or every connection of the user.
     */
    public function connections(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $accountId = (int) ($request->getQuery()['account_id'] ?? 0);

        if ($accountId) {
            return $this->jsonSuccess($this->connectionService->findForAccount($accountId, $userId));
        }

        return $this->jsonSuccess($this->connectionService->findAllForUser($userId));
    }

    /**
     * Ask for a sync. Returns 202 immediately: the scheduler picks the request
     * up on its next tick, under a minute away. Running it inline made the user
     * wait through four to five WebSocket sessions, with a proxy timeout able to
     * cut the response before the import finished.
     */
    public function sync(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $connectionId = (int) $request->getRouteParam('id');

        $result = $this->syncService->requestSync($connectionId, $userId);

        return $this->jsonSuccess($result, null, 202);
    }

    /**
     * Delete a connection.
     */
    public function deleteConnection(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $connectionId = (int) $request->getRouteParam('id');

        $connection = $this->connectionService->requireOwnedConnection($connectionId, $userId);
        $this->connectionService->deleteConnection((int) $connection['id']);

        return $this->jsonSuccess(['message_key' => 'broker.success.disconnected']);
    }

    /**
     * Get sync logs for a connection.
     */
    public function syncLogs(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $connectionId = (int) $request->getRouteParam('id');

        $this->connectionService->requireOwnedConnection($connectionId, $userId);

        return $this->jsonSuccess($this->syncLogRepo->findByConnectionId($connectionId));
    }
}
