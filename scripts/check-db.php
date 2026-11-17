<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';
try {
    $result = App\Core\Database::connection()->query('SELECT 1')->fetchColumn();
    if ((int) $result !== 1) {
        throw new RuntimeException('Truy vấn kiểm tra thất bại.');
    }
    echo "Kết nối PDO MySQL thành công.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
