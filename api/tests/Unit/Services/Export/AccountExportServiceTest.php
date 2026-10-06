<?php

namespace Tests\Unit\Services\Export;

use App\Exceptions\NotFoundException;
use App\Repositories\CustomFieldDefinitionRepository;
use App\Repositories\CustomFieldValueRepository;
use App\Repositories\PartialExitRepository;
use App\Repositories\PositionRepository;
use App\Repositories\SetupRepository;
use App\Repositories\UserRepository;
use App\Services\AccountService;
use App\Services\Export\AccountExportService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class AccountExportServiceTest extends TestCase
{
    private AccountService $accounts;
    private PositionRepository $positions;
    private PartialExitRepository $exits;
    private CustomFieldDefinitionRepository $fieldDefinitions;
    private CustomFieldValueRepository $fieldValues;
    private SetupRepository $setups;
    private UserRepository $users;
    private AccountExportService $service;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->accounts = $this->createMock(AccountService::class);
        $this->positions = $this->createMock(PositionRepository::class);
        $this->exits = $this->createMock(PartialExitRepository::class);
        $this->fieldDefinitions = $this->createMock(CustomFieldDefinitionRepository::class);
        $this->fieldValues = $this->createMock(CustomFieldValueRepository::class);
        $this->setups = $this->createMock(SetupRepository::class);
        $this->users = $this->createMock(UserRepository::class);
        $this->service = new AccountExportService(
            $this->accounts, $this->positions, $this->exits, $this->fieldDefinitions, $this->fieldValues, $this->setups, $this->users
        );
        $this->now = new DateTimeImmutable('2026-10-06 09:30:00');
    }

    private function account(array $overrides = []): array
    {
        return array_merge([
            'id' => 175, 'user_id' => 50, 'name' => '(A) PF FTMO', 'account_type' => 'PROP_FIRM',
            'stage' => 'CHALLENGE', 'broker' => 'FTMO', 'currency' => 'EUR',
            'initial_capital' => '10000.00', 'current_capital' => '9868.55',
            'max_drawdown' => '1000.00', 'daily_drawdown' => '500.00',
            'profit_target' => '1000.00', 'profit_split' => '80.00',
        ], $overrides);
    }

    /** A closed trade with two targets, as the repository returns it. */
    private function trade(array $overrides = []): array
    {
        return array_merge([
            'position_id' => '3200', 'trade_id' => '3100', 'position_type' => 'TRADE',
            'order_status' => null, 'order_created_at' => null, 'order_expires_at' => null,
            'trade_status' => 'CLOSED', 'opened_at' => '2026-09-24 10:22:40',
            'secured_at' => '2026-09-24 10:50:00', 'closed_at' => '2026-09-24 11:20:09',
            'symbol' => 'GER40', 'direction' => 'BUY', 'size' => '0.10000', 'remaining_size' => '0.00000',
            'point_value' => '1.00000', 'entry_price' => '25219.47000', 'sl_price' => '25140.00000',
            'sl_points' => '79.47', 'be_price' => '25258.14000', 'be_points' => '38.67', 'be_size' => '0.05000',
            'be_reached' => '1',
            'targets' => '[{"id":"tp1","label":"TP1","price":25273.6,"points":54.13,"size":0.04},'
                . '{"id":"tp2","label":"TP2","price":25400,"points":180.53,"size":0.06}]',
            'avg_exit_price' => '25264.32400', 'exit_type' => 'TP', 'risk_reward' => '0.5650',
            'pnl' => '4.49', 'pnl_percent' => '0.0449', 'plan_name' => 'Plan DAX',
            'plan_adherence' => 'OUT_OF_PLAN', 'plan_adherence_reason' => 'direction BUY not allowed',
            'setup' => '["Break 2","Zone demand"]', 'notes' => 'Patience',
        ], $overrides);
    }

    private function order(array $overrides = []): array
    {
        return array_merge($this->trade([
            'position_id' => '3300', 'trade_id' => null, 'position_type' => 'ORDER',
            'order_status' => 'CANCELLED', 'order_created_at' => '2026-09-25 08:00:00',
            'order_expires_at' => '2026-09-25 22:00:00', 'trade_status' => null, 'opened_at' => null,
            'secured_at' => null, 'closed_at' => null, 'remaining_size' => null, 'be_reached' => null,
            'avg_exit_price' => null, 'exit_type' => null, 'risk_reward' => null, 'pnl' => null,
            'pnl_percent' => null, 'notes' => null,
        ]), $overrides);
    }

    private function exit(string $at, string $price, string $size, string $type, ?string $targetId, string $pnl): array
    {
        return ['exited_at' => $at, 'exit_price' => $price, 'size' => $size, 'exit_type' => $type, 'target_id' => $targetId, 'pnl' => $pnl];
    }

    /** The exits of the sample trade: TP1 hit, then the rest closed at break-even. */
    private function sampleExits(): array
    {
        return [3100 => [
            $this->exit('2026-09-24 10:40:00', '25273.60000', '0.04000', 'TP', 'tp1', '2.16'),
            $this->exit('2026-09-24 11:20:09', '25258.14000', '0.06000', 'BE', null, '2.33'),
        ]];
    }

    private function givenExport(
        array $positions,
        array $exitsByTrade = [],
        string $locale = 'fr',
        array $account = [],
        array $adjustments = [],
        array $definitions = [],
        array $valuesByTrade = [],
        ?array $setupCatalogue = null
    ): array {
        $this->accounts->method('get')->with(50, 175)->willReturn($this->account($account));
        $this->accounts->method('listAdjustments')->with(50, 175)->willReturn($adjustments);
        $this->positions->method('findForAccountExport')->with(50, 175)->willReturn($positions);
        $this->exits->method('findByTradeIds')->willReturn($exitsByTrade);
        $this->fieldDefinitions->method('findAllByUserId')->with(50)->willReturn($definitions);
        $this->fieldValues->method('findByTradeIds')->willReturn($valuesByTrade);
        $this->setups->method('findAllByUserId')->with(50)->willReturn($setupCatalogue ?? [
            ['label' => 'M15', 'category' => 'timeframe'],
            ['label' => 'Break 2', 'category' => 'pattern'],
            ['label' => 'Zone demand', 'category' => 'context'],
        ]);
        $this->users->method('findById')->with(50)->willReturn(['id' => 50, 'locale' => $locale]);

        return $this->service->build(50, 175, $this->now);
    }

    private function sheet(array $export, int $index): array
    {
        return $export['sheets'][$index];
    }

    /** Data rows of a sheet as header => value maps. */
    private function rows(array $export, int $index): array
    {
        $rows = $this->sheet($export, $index)['rows'];
        return array_map(fn ($row) => array_combine($rows[0], $row), array_slice($rows, 1));
    }

    /** The account sheet's field block as label => value. */
    private function accountFields(array $export): array
    {
        $map = [];
        foreach (array_slice($this->sheet($export, 0)['rows'], 1) as $row) {
            if ($row === []) {
                break;
            }
            $map[$row[0]] = $row[1];
        }
        return $map;
    }

    private function pick(array $row, array $keys): array
    {
        return array_map(fn ($k) => $row[$k], $keys);
    }

    // ── Sheets ────────────────────────────────────────────────────

    public function testBuild_givesTheAccountItsTradesAndItsUnexecutedOrders(): void
    {
        $export = $this->givenExport([]);

        $this->assertSame(['Compte', 'Trades', 'Ordres non exécutés'], array_column($export['sheets'], 'title'));
        foreach ($export['sheets'] as $sheet) {
            $this->assertSame([1], array_slice($sheet['header_rows'], 0, 1));
        }
    }

    public function testBuild_columnsAreTheSameWhateverTheData(): void
    {
        $empty = $this->givenExport([]);
        $this->setUp();
        $full = $this->givenExport([$this->trade(), $this->order()], $this->sampleExits());

        $this->assertSame($this->sheet($empty, 1)['rows'][0], $this->sheet($full, 1)['rows'][0]);
        $this->assertSame($this->sheet($empty, 2)['rows'][0], $this->sheet($full, 2)['rows'][0]);
    }

    // ── Account ───────────────────────────────────────────────────

    public function testBuild_accountSheet_inTheUsersWords(): void
    {
        $fields = $this->accountFields($this->givenExport([]));

        $this->assertSame([
            'Nom' => '(A) PF FTMO', 'Type' => 'Prop Firm', 'Phase' => 'Challenge', 'Broker' => 'FTMO',
            'Devise' => 'EUR', 'Capital initial' => 10000.0, 'Solde' => 9868.55, 'Drawdown max' => 1000.0,
            'Drawdown journalier' => 500.0, 'Objectif de profit' => 1000.0, 'Partage des profits (%)' => 80.0,
        ], array_diff_key($fields, ['Exporté le' => true]));
        $this->assertEquals($this->now, $fields['Exporté le']);
    }

    public function testBuild_accountSheet_leavesUnsetValuesEmpty(): void
    {
        $fields = $this->accountFields($this->givenExport([], [], 'fr', [
            'stage' => null, 'broker' => null, 'max_drawdown' => null, 'daily_drawdown' => null,
            'profit_target' => null, 'profit_split' => null,
        ]));

        foreach (['Phase', 'Broker', 'Drawdown max', 'Drawdown journalier', 'Objectif de profit', 'Partage des profits (%)'] as $label) {
            $this->assertNull($fields[$label], $label);
        }
    }

    public function testBuild_accountSheet_listsEachBalanceAdjustmentOldestFirst(): void
    {
        $export = $this->givenExport([], [], 'fr', [], [
            ['adjusted_at' => '2026-09-14 18:00:00', 'amount' => '-3.73', 'reason' => 'Frais oubliés'],
            ['adjusted_at' => '2026-06-03 12:00:00', 'amount' => '-2.00', 'reason' => null],
        ]);

        $rows = $this->sheet($export, 0)['rows'];
        $blank = array_search([], $rows, true);
        $this->assertNotFalse($blank);
        $this->assertSame(['Ajustement de solde', 'Montant', 'Motif'], $rows[$blank + 1]);
        $this->assertEquals([new DateTimeImmutable('2026-06-03 12:00:00'), -2.0, null], $rows[$blank + 2]);
        $this->assertEquals([new DateTimeImmutable('2026-09-14 18:00:00'), -3.73, 'Frais oubliés'], $rows[$blank + 3]);
        $this->assertSame([1, $blank + 2], $this->sheet($export, 0)['header_rows']);
    }

    public function testBuild_accountSheet_withoutAdjustments_hasNoAdjustmentTable(): void
    {
        $export = $this->givenExport([]);

        $this->assertNotContains([], $this->sheet($export, 0)['rows']);
        $this->assertSame([1], $this->sheet($export, 0)['header_rows']);
    }

    // ── Trades: one row per leg ───────────────────────────────────

    public function testBuild_trades_oneRowPerTargetWithTheExitThatHitItThenOneRowPerOtherExit(): void
    {
        $rows = $this->rows($this->givenExport([$this->trade()], $this->sampleExits()), 1);

        $leg = ['Objectif', 'Objectif (prix)', 'Objectif (taille)', 'Sortie (date)',
            'Sortie (prix)', 'Sortie (taille)', 'Sortie (type)', 'Sortie (P&L)'];
        $this->assertCount(3, $rows);
        $this->assertEquals(
            ['TP1', 25273.6, 0.04, new DateTimeImmutable('2026-09-24 10:40:00'), 25273.6, 0.04, 'Take Profit', 2.16],
            $this->pick($rows[0], $leg)
        );
        $this->assertEquals(['TP2', 25400.0, 0.06, null, null, null, null, null], $this->pick($rows[1], $leg));
        $this->assertEquals(
            [null, null, null, new DateTimeImmutable('2026-09-24 11:20:09'), 25258.14, 0.06, 'Breakeven', 2.33],
            $this->pick($rows[2], $leg)
        );
    }

    public function testBuild_trades_repeatTheTradeOnEachOfItsRows(): void
    {
        $rows = $this->rows($this->givenExport([$this->trade()], $this->sampleExits()), 1);

        $expected = [
            'N° trade' => 3200, 'Statut' => 'Fermé', 'Ordre placé le' => null,
            'Ouverture' => new DateTimeImmutable('2026-09-24 10:22:40'),
            'Sécurisé le' => new DateTimeImmutable('2026-09-24 10:50:00'),
            'Clôture' => new DateTimeImmutable('2026-09-24 11:20:09'), 'Symbole' => 'GER40', 'Sens' => 'Achat',
            'Taille' => 0.1, 'Taille restante' => 0.0, "Prix d'entrée" => 25219.47,
            'SL (prix)' => 25140.0, 'BE (prix)' => 25258.14, 'BE (taille)' => 0.05,
            'Prix de sortie moyen' => 25264.324, 'Type de sortie' => 'Take Profit', 'R:R' => 0.565,
            'P&L du trade' => 4.49, 'Plan' => 'Plan DAX', 'Respect du plan' => 'Hors plan',
            'Raison' => 'direction BUY not allowed', 'Setup : Timeframe' => null, 'Setup : Pattern' => 'Break 2',
            'Setup : Contexte' => 'Zone demand', 'Setup : non catégorisé' => null,
            'Champs personnalisés' => null, 'Notes' => 'Patience',
        ];
        foreach ($rows as $row) {
            $this->assertEquals($expected, array_intersect_key($row, $expected));
        }
    }

    public function testBuild_trades_aTradeWithoutTargetAndASingleExitIsOneRow(): void
    {
        $rows = $this->rows($this->givenExport(
            [$this->trade(['targets' => null])],
            [3100 => [$this->exit('2026-09-24 11:00:00', '25140', '0.1', 'SL', null, '-7.95')]]
        ), 1);

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['Objectif']);
        $this->assertSame('Stop Loss', $rows[0]['Sortie (type)']);
        $this->assertSame(-7.95, $rows[0]['Sortie (P&L)']);
    }

    public function testBuild_trades_anOpenTradeWithNothingYetIsStillOneRow(): void
    {
        $rows = $this->rows($this->givenExport([$this->trade([
            'trade_status' => 'OPEN', 'targets' => null, 'closed_at' => null, 'be_reached' => '0',
            'exit_type' => null, 'pnl' => null,
        ])]), 1);

        $this->assertCount(1, $rows);
        $this->assertSame('Ouvert', $rows[0]['Statut']);
        $this->assertNull($rows[0]['Sortie (date)']);
    }

    public function testBuild_trades_severalExitsOnOneTargetGiveOneRowEach(): void
    {
        $rows = $this->rows($this->givenExport([$this->trade()], [3100 => [
            $this->exit('2026-09-24 10:40:00', '25273.6', '0.02', 'TP', 'tp1', '1.08'),
            $this->exit('2026-09-24 10:41:00', '25273.6', '0.02', 'TP', 'tp1', '1.08'),
        ]]), 1);

        $this->assertSame(['TP1', 'TP1', 'TP2'], array_column($rows, 'Objectif'));
        $this->assertSame([1.08, 1.08, null], array_column($rows, 'Sortie (P&L)'));
    }

    public function testBuild_trades_anExitOnAnUnknownTargetComesAfterTheTargets(): void
    {
        $rows = $this->rows($this->givenExport([$this->trade()], [3100 => [
            $this->exit('2026-09-24 10:40:00', '25300', '0.1', 'MANUAL', 'gone', '8.05'),
        ]]), 1);

        $this->assertSame(['TP1', 'TP2', null], array_column($rows, 'Objectif'));
        $this->assertSame('Manuel', $rows[2]['Sortie (type)']);
    }

    public function testBuild_trades_customFieldsShareOneCellAsNameAndValue(): void
    {
        $rows = $this->rows($this->givenExport(
            [$this->trade(['targets' => null])],
            [],
            'fr',
            [],
            [],
            [
                ['id' => 1, 'name' => 'Émotion', 'field_type' => 'SELECT'],
                ['id' => 2, 'name' => 'Respect du timing', 'field_type' => 'BOOLEAN'],
                ['id' => 3, 'name' => 'Note /10', 'field_type' => 'NUMBER'],
            ],
            [3100 => [
                ['field_id' => 3, 'name' => 'Note /10', 'field_type' => 'NUMBER', 'value' => '7.5'],
                ['field_id' => 1, 'name' => 'Émotion', 'field_type' => 'SELECT', 'value' => 'Peur'],
                ['field_id' => 2, 'name' => 'Respect du timing', 'field_type' => 'BOOLEAN', 'value' => 'false'],
            ]]
        ), 1);

        $this->assertSame('Émotion : Peur ; Respect du timing : Non ; Note /10 : 7.5', $rows[0]['Champs personnalisés']);
    }

    public function testBuild_trades_sortSetupsByTheirTypology(): void
    {
        $rows = $this->rows($this->givenExport([$this->trade([
            'targets' => null, 'setup' => '["M15","Break 2","Fibo 0.618","Zone demand","Retest"]',
        ])], [], 'fr', [], [], [], [], [
            ['label' => 'M15', 'category' => 'timeframe'],
            ['label' => 'Break 2', 'category' => 'pattern'],
            ['label' => 'Retest', 'category' => 'pattern'],
            ['label' => 'Zone demand', 'category' => 'context'],
            ['label' => 'Fibo 0.618', 'category' => null],
        ]), 1);

        $this->assertSame('M15', $rows[0]['Setup : Timeframe']);
        $this->assertSame('Break 2, Retest', $rows[0]['Setup : Pattern']);
        $this->assertSame('Zone demand', $rows[0]['Setup : Contexte']);
        $this->assertSame('Fibo 0.618', $rows[0]['Setup : non catégorisé']);
    }

    public function testBuild_trades_aSetupNoLongerInTheCatalogueIsUncategorized(): void
    {
        $rows = $this->rows($this->givenExport([$this->trade(['setup' => '["Deleted tag","M15"]', 'targets' => null])]), 1);

        $this->assertSame('M15', $rows[0]['Setup : Timeframe']);
        $this->assertSame('Deleted tag', $rows[0]['Setup : non catégorisé']);
    }

    public function testBuild_trades_keepASetupThatIsNotAJsonListAsIs(): void
    {
        $rows = $this->rows($this->givenExport([$this->trade(['setup' => 'Old free text setup', 'targets' => null])]), 1);

        $this->assertSame('Old free text setup', $rows[0]['Setup : non catégorisé']);
    }

    public function testBuild_trades_dropColumnsThatOnlyRestateOthers(): void
    {
        $header = $this->sheet($this->givenExport([]), 1)['rows'][0];

        foreach (['BE atteint', 'Valeur du point', 'SL (points)', 'BE (points)', 'Objectif (points)', 'P&L du trade (%)', 'Setups'] as $gone) {
            $this->assertNotContains($gone, $header, $gone);
        }
    }

    public function testBuild_trades_aTradeFromAnOrderKeepsWhenTheOrderWasPlaced(): void
    {
        $rows = $this->rows($this->givenExport([$this->trade([
            'order_status' => 'EXECUTED', 'order_created_at' => '2026-09-24 08:00:00', 'targets' => null,
        ])]), 1);

        $this->assertEquals(new DateTimeImmutable('2026-09-24 08:00:00'), $rows[0]['Ordre placé le']);
        $this->assertSame('Fermé', $rows[0]['Statut']);
    }

    public function testBuild_trades_leaveOutUnexecutedOrders(): void
    {
        $this->assertSame([], $this->rows($this->givenExport([$this->order()]), 1));
    }

    // ── Unexecuted orders: one row per target ─────────────────────

    public function testBuild_orders_oneRowPerTarget(): void
    {
        $rows = $this->rows($this->givenExport([$this->trade(), $this->order()], $this->sampleExits()), 2);

        $this->assertCount(2, $rows);
        $this->assertSame(['TP1', 'TP2'], array_column($rows, 'Objectif'));
        $expected = [
            'N° ordre' => 3300, 'Statut' => 'Annulé', 'Placé le' => new DateTimeImmutable('2026-09-25 08:00:00'),
            'Expiration' => new DateTimeImmutable('2026-09-25 22:00:00'), 'Symbole' => 'GER40', 'Sens' => 'Achat',
            'Taille' => 0.1, "Prix d'entrée" => 25219.47, 'SL (prix)' => 25140.0,
            'Plan' => 'Plan DAX', 'Respect du plan' => 'Hors plan', 'Setup : Pattern' => 'Break 2',
            'Setup : Contexte' => 'Zone demand',
        ];
        $this->assertEquals($expected, array_intersect_key($rows[0], $expected));
        $this->assertSame(25400.0, $rows[1]['Objectif (prix)']);
    }

    public function testBuild_orders_anOrderWithoutTargetIsOneRow(): void
    {
        $rows = $this->rows($this->givenExport([$this->order(['targets' => null, 'order_status' => 'PENDING'])]), 2);

        $this->assertCount(1, $rows);
        $this->assertSame('En attente', $rows[0]['Statut']);
        $this->assertNull($rows[0]['Objectif']);
    }

    // ── Language, file name, access ───────────────────────────────

    public function testBuild_inEnglish_translatesLabelsAndValues(): void
    {
        $export = $this->givenExport([$this->trade(), $this->order()], $this->sampleExits(), 'en');

        $this->assertSame(['Account', 'Trades', 'Unexecuted orders'], array_column($export['sheets'], 'title'));
        $this->assertSame('Prop Firm', $this->accountFields($export)['Type']);
        $trade = $this->rows($export, 1)[2];
        $this->assertSame('Buy', $trade['Direction']);
        $this->assertSame('Closed', $trade['Status']);
        $this->assertSame('Breakeven', $trade['Exit (type)']);
        $this->assertSame('Break 2', $trade['Setup: Pattern']);
        $this->assertSame('Out of plan', $trade['Plan adherence']);
        $this->assertSame('Cancelled', $this->rows($export, 2)[0]['Status']);
    }

    public function testBuild_withAnUnknownLocale_fallsBackToEnglish(): void
    {
        $this->assertSame('Account', $this->givenExport([], [], 'de')['sheets'][0]['title']);
    }

    public function testBuild_namesTheFileAfterTheAccountAndTheDay(): void
    {
        $this->assertSame('a-pf-ftmo-2026-10-06.xlsx', $this->givenExport([])['filename']);
    }

    public function testBuild_withANameMadeOnlyOfSymbols_fallsBackToAGenericFileName(): void
    {
        $this->assertSame('account-2026-10-06.xlsx', $this->givenExport([], [], 'fr', ['name' => '€€€'])['filename']);
    }

    public function testBuild_onAnAccountTheUserCannotSee_readsNothingElse(): void
    {
        $this->accounts->method('get')->willThrowException(new NotFoundException('accounts.error.not_found'));
        $this->positions->expects($this->never())->method('findForAccountExport');

        $this->expectException(NotFoundException::class);
        $this->service->build(50, 175, $this->now);
    }
}
