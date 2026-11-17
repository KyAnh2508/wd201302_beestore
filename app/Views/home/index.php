<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
</head>
<body>
    <main>
        <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
        <p>Khung PHP MVC đã sẵn sàng.</p>
        <p>Controller → View hoạt động. Database chỉ kết nối khi Model cần dùng.</p>
    </main>
</body>
</html>
