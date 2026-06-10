<?php
require_once __DIR__ . '/config/db.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Проверка подключения</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="page narrow-page">
    <section class="panel">
        <h1>Подключение к базе данных успешно</h1>
        <p>Приложение подключилось к базе <code>education_tests</code>.</p>
        <a class="button button-primary" href="tests.php">Перейти к тестам</a>
    </section>
</main>
</body>
</html>
