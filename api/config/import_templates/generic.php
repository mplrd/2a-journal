<?php

/**
 * Standard import template — matches the downloadable CSV template exactly.
 * Columns: Symbol, Direction, Entry Price, Size, Open Date, Close Date, Exit Price
 */
return [
    'broker' => 'generic',
    'label' => 'Standard (CSV)',
    'file_types' => ['csv', 'xlsx'],
    'columns' => [
        'symbol' => [
            'names' => ['Symbol'],
        ],
        'direction' => [
            'names' => ['Direction'],
            'map' => [
                'BUY' => 'BUY', 'Buy' => 'BUY',
                'SELL' => 'SELL', 'Sell' => 'SELL',
            ],
        ],
        'entry_price' => [
            'names' => ['Entry Price'],
        ],
        'size' => [
            'names' => ['Size'],
        ],
        'opened_at' => [
            'names' => ['Open Date'],
            'format' => 'Y-m-d H:i:s',
        ],
        'closed_at' => [
            'names' => ['Close Date'],
            'format' => 'Y-m-d H:i:s',
        ],
        'exit_price' => [
            'names' => ['Exit Price'],
        ],
    ],
    'grouping' => [
        'key' => ['symbol', 'direction', 'entry_price'],
        'partial_exits' => true,
    ],
];
