<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

requireLogin();

$currentUser = getCurrentUser();
$userId = (int) $currentUser['id'];

$stmt = $conn->prepare(
    'SELECT r.score, r.total_questions, r.passed_at, t.title
     FROM results r
     INNER JOIN tests t ON t.id = r.test_id
     WHERE r.user_id = ?
     ORDER BY r.passed_at DESC, r.id DESC'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$results = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мои результаты</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="page">
    <section class="topbar panel panel-compact">
        <div class="topbar-status">
            <span>Пользователь: <strong><?php echo htmlspecialchars($currentUser['name']); ?></strong></span>
        </div>
        <div class="topbar-actions">
            <a class="button button-secondary" href="tests.php">К тестам</a>
            <?php if (isTeacher()): ?>
                <a class="button button-secondary" href="teacher_dashboard.php">Кабинет преподавателя</a>
            <?php endif; ?>
            <?php if (isAdmin()): ?>
                <a class="button button-secondary" href="admin_panel.php">Админ-панель</a>
            <?php endif; ?>
            <a class="button button-secondary" href="logout.php">Выйти</a>
        </div>
    </section>

    <section class="page-header">
        <div>
            <p class="eyebrow">Личный раздел</p>
            <h1>Мои результаты</h1>
            <p class="lead">Здесь собраны все ваши попытки прохождения тестов.</p>
        </div>
        <a class="button button-secondary" href="index.php">На главную</a>
    </section>

    <section class="cards-grid">
        <?php if ($results->num_rows > 0): ?>
            <?php while ($row = $results->fetch_assoc()): ?>
                <?php $percentage = $row['total_questions'] > 0 ? round(($row['score'] / $row['total_questions']) * 100) : 0; ?>
                <article class="panel test-card">
                    <p class="card-label">Попытка от <?php echo htmlspecialchars($row['passed_at']); ?></p>
                    <h2><?php echo htmlspecialchars($row['title']); ?></h2>
                    <p>Результат: <strong><?php echo (int) $row['score']; ?>/<?php echo (int) $row['total_questions']; ?></strong></p>
                    <p class="meta">Правильных ответов: <?php echo $percentage; ?>%</p>
                </article>
            <?php endwhile; ?>
        <?php else: ?>
            <article class="panel empty-state">
                <h2>Результатов пока нет</h2>
                <p>Сначала пройдите хотя бы один тест, после этого история появится на этой странице.</p>
                <a class="button button-primary" href="tests.php">Перейти к тестам</a>
            </article>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
