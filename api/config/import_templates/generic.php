<?php

/**
 * Generic import template — covers standard CSV exports with common column names.
 * Supports French and English headers, multiple naming conventions.
 */
return [
    'broker' => 'generic',
    'label' => 'Standard (CSV)',
    'file_types' => ['csv', 'xlsx'],
    'columns' => [
        'symbol' => [
            'names' => [
                'Symbol', 'Symbole', 'Instrument', 'Asset', 'Actif',
                'Ticker', 'Market', 'Marché', 'Pair', 'Paire',
            ],
        ],
        'direction' => [
            'names' => [
                'Direction', 'Side', 'Type', 'Action',
                'Sens', 'Sens d\'ouverture', 'Trade Type', 'Position',
            ],
            'map' => [
                'Buy' => 'BUY', 'BUY' => 'BUY', 'Long' => 'BUY', 'LONG' => 'BUY',
                'Acheter' => 'BUY', 'Achat' => 'BUY',
                'Sell' => 'SELL', 'SELL' => 'SELL', 'Short' => 'SELL', 'SHORT' => 'SELL',
                'Vendre' => 'SELL', 'Vente' => 'SELL',
            ],
        ],
        'closed_at' => [
            'names' => [
                'Close Date', 'Close Time', 'Closing Time', 'Closed At',
                'Date de clôture', 'Heure de clôture', 'Date clôture',
                'Exit Date', 'Exit Time', 'Date de sortie',
            ],
            'format' => 'Y-m-d H:i:s',
        ],
        'opened_at' => [
            'names' => [
                'Open Date', 'Open Time', 'Opening Time', 'Opened At',
                'Date d\'ouverture', 'Heure d\'ouverture', 'Date ouverture',
                'Entry Date', 'Entry Time', 'Date d\'entrée',
            ],
            'format' => 'Y-m-d H:i:s',
        ],
        'entry_price' => [
            'names' => [
                'Entry Price', 'Open Price', 'Entry', 'Open',
                'Prix d\'entrée', 'Cours d\'entrée', 'Prix ouverture',
            ],
        ],
        'exit_price' => [
            'names' => [
                'Exit Price', 'Close Price', 'Closing Price', 'Exit', 'Close',
                'Prix de sortie', 'Prix de clôture', 'Cours de clôture', 'Prix clôture',
            ],
        ],
        'size' => [
            'names' => [
                'Size', 'Quantity', 'Volume', 'Lots', 'Qty',
                'Taille', 'Quantité', 'Quantité de clôture',
            ],
        ],
        'pnl' => [
            'names' => [
                'PnL', 'P&L', 'Profit', 'Profit/Loss', 'Net Profit',
                'Résultat', 'Gain', 'Bénéfice',
            ],
            'names_pattern' => ['/P\s*&?\s*L/i', '/profit/i', '/nets$/i'],
        ],
        'pips' => [
            'names' => ['Pips', 'Points'],
        ],
        'comment' => [
            'names' => [
                'Comment', 'Comments', 'Notes', 'Note',
                'Commentaire', 'Commentaires',
            ],
        ],
    ],
    'grouping' => [
        'key' => ['symbol', 'direction', 'entry_price'],
        'partial_exits' => true,
    ],
];
