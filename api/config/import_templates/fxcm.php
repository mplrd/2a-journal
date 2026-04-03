<?php

return [
    'broker' => 'fxcm',
    'label' => 'FXCM',
    'file_types' => ['xml'],
    'thousands_separator' => ',',
    'row_filter' => [
        'column' => 'Ticket №',
        'pattern' => '/^\s*\d*\s*$/', // Keep rows where Ticket is numeric or empty (open/close pairs)
    ],
    'multi_row' => 2,
    'multi_row_merge' => [
        // Direction is determined by which price column is filled on the OPEN row
        // SELL: Vendu has entry price on OPEN, Achete has exit price on CLOSE
        // BUY:  Achete has entry price on OPEN, Vendu has exit price on CLOSE
        'direction_from' => ['sell_column' => 'Vendu', 'buy_column' => 'Achete'],
        'opened_at' => ['row' => 0, 'column' => 'Date'],
        'closed_at' => ['row' => 1, 'column' => 'Date'],
        'pnl' => ['row' => 1, 'column' => 'G/P Net'],
        'pips' => ['row' => 1, 'column' => 'Marges (pips)'],
    ],
    'columns' => [
        'symbol' => [
            'names' => ['Monnaie', 'Currency'],
        ],
        'direction' => [
            'names' => ['_direction'],
        ],
        'opened_at' => [
            'names' => ['_opened_at'],
            'format' => 'd/m/Y G:i',
        ],
        'closed_at' => [
            'names' => ['_closed_at'],
            'format' => 'd/m/Y G:i',
        ],
        'entry_price' => [
            'names' => ['_entry_price'],
        ],
        'exit_price' => [
            'names' => ['_exit_price'],
        ],
        'size' => [
            'names' => ['Volume'],
        ],
        'pnl' => [
            'names' => ['G/P Net', 'Net P/L'],
        ],
        'pips' => [
            'names' => ['Marges (pips)', 'Spread (pips)'],
        ],
    ],
    'grouping' => [
        'key' => ['symbol', 'direction', 'entry_price', 'opened_at'],
        'partial_exits' => false,
    ],
];
