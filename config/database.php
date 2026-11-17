<?php
declare(strict_types=1);

return [
    'host' => getenv('DB_HOST') !== false ? getenv('DB_HOST') : '127.0.0.1',
    'port' => getenv('DB_PORT') !== false ? getenv('DB_PORT') : '3306',
    'database' => getenv('DB_NAME') !== false ? getenv('DB_NAME') : 'website_ban_quan_ao',
    'username' => getenv('DB_USER') !== false ? getenv('DB_USER') : 'root',
    'password' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
];
