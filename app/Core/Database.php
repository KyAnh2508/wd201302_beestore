<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }
        $config = require BASE_PATH . '/config/database.php';
        // Prevent separators from changing the DSN structure.
        foreach (['host', 'port', 'database'] as $key) {
            if ($config[$key] === '' || preg_match('/[;\x00-\x1f]/', $config[$key])) {
                throw new RuntimeException('Cấu hình database không hợp lệ.');
            }
        }
        if (!ctype_digit((string) $config['port']) || (int) $config['port'] < 1 || (int) $config['port'] > 65535) {
            throw new RuntimeException('DB_PORT không hợp lệ.');
        }
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'], $config['port'], $config['database']);
        try {
            self::$connection = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            // Do not expose credentials or database errors to visitors.
            throw new RuntimeException('Không thể kết nối database. Kiểm tra cấu hình và MySQL.');
        }
        return self::$connection;
    }
}
