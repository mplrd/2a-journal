<?php

return [
    'broker' => 'ftmo',
    'label' => 'FTMO',
    'file_types' => ['csv', 'xlsx'],
    'columns' => [
        'symbol' => [
            'names' => ['Symbole', 'Symbol'],
        ],
        'direction' => [
            'names' => ['Type'],
            'map' => [
                'sell' => 'SELL', 'Sell' => 'SELL', 'SELL' => 'SELL',
                'buy' => 'BUY', 'Buy' => 'BUY', 'BUY' => 'BUY',
            ],
        ],
        'opened_at' => [
            'names' => ['Ouvrir', 'Open'],
            'format' => 'Y-m-d H:i:s',
        ],
        'closed_at' => [
            'names' => ['Fermeture', 'Close'],
            'format' => 'Y-m-d H:i:s',
        ],
        'entry_price' => [
            'names' => ['Prix', 'Price'],
        ],
        'exit_price' => [
            'names' => ['Prix_2', 'Price_2'],
        ],
        'size' => [
            'names' => ['Volume'],
        ],
        'pnl' => [
            'names' => ['Profit'],
        ],
        'pips' => [
            'names' => ['Pips'],
        ],
    ],
    'grouping' => [
        'key' => ['symbol', 'direction', 'entry_price', 'opened_at'],
        'partial_exits' => true,
    ],
];
