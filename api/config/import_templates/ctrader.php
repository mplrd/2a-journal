<?php

return [
    'broker' => 'ctrader',
    'label' => 'cTrader',
    'file_types' => ['xlsx', 'csv'],
    'columns' => [
        'symbol' => [
            'names' => ['Symbole', 'Symbol'],
        ],
        'direction' => [
            'names' => ['Sens d\'ouverture', 'Direction', 'Trade Type'],
            'map' => [
                'Acheter' => 'BUY', 'Buy' => 'BUY',
                'Vendre' => 'SELL', 'Sell' => 'SELL',
            ],
        ],
        'closed_at' => [
            'names' => ['Heure de clôture', 'Closing Time', 'Close Time'],
            'format' => 'd/m/Y H:i:s',
        ],
        'entry_price' => [
            'names' => ['Cours d\'entrée', 'Entry Price', 'Open Price'],
        ],
        'exit_price' => [
            'names' => ['Price de clôture', 'Closing Price', 'Close Price'],
        ],
        'size' => [
            'names' => ['Quantité de clôture', 'Closing Quantity', 'Volume'],
        ],
        'pnl' => [
            'names_pattern' => ['/nets$/i'],
            'detect_currency' => true,
        ],
        'pips' => [
            'names' => ['Pips'],
        ],
        'comment' => [
            'names' => ['Commentaire', 'Comment'],
        ],
    ],
    'grouping' => [
        'key' => ['symbol', 'direction', 'entry_price'],
        'partial_exits' => true,
    ],
];
