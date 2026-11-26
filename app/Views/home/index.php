<?php
// Asset URLs work both at the site root and in an Apache subdirectory.
$assetBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/') . '/';
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <base href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="assets/css/style.css">
    <script defer src="assets/js/data.js"></script>
    <script defer src="assets/js/app.js"></script>
</head>
<body>
    <div id="app"></div>
    <div id="toast" role="status" aria-live="polite"></div>
    <dialog id="modal"></dialog>
    <noscript>Vui lòng bật JavaScript để sử dụng giao diện Bee Store.</noscript>
</body>
</html>
