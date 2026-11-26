<?php
declare(strict_types=1);

// Let PHP's development server serve existing public assets directly.
if (PHP_SAPI === 'cli-server') {
    $assetPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $assetFile = is_string($assetPath) ? realpath(__DIR__ . rawurldecode($assetPath)) : false;
    if ($assetFile !== false && str_starts_with($assetFile, __DIR__ . DIRECTORY_SEPARATOR)
        && is_file($assetFile) && strtolower(pathinfo($assetFile, PATHINFO_EXTENSION)) !== 'php') {
        return false;
    }
}

require dirname(__DIR__) . '/bootstrap.php';

// Explicit route map: user input is never used as a file path or class name.
$route = $_GET['url'] ?? '/';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
if ($route === '/' && is_string($path)) {
    if ($base !== '' && str_starts_with($path, $base . '/')) {
        $path = substr($path, strlen($base));
    }
    $route = $path;
}
if (!is_string($route)) {
    http_response_code(404);
    exit('404 - Không tìm thấy trang');
}
$route = trim($route, '/');
try {
    switch ($route) {
        case '':
        case 'index.php':
        case 'home':
            (new App\Controllers\HomeController())->index();
            break;
        default:
            http_response_code(404);
            echo '404 - Không tìm thấy trang';
    }
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    echo 'Có lỗi xảy ra. Vui lòng thử lại sau.';
}
