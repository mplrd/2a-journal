<?php

namespace App\Core;

use PDO;

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../../config/database.php';

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['name'],
                $config['charset']
            );

            self::$instance = new PDO($dsn, $config['user'], $config['password'], $config['options']);
        }

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
