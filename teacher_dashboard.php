<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

requireTeacher();

$currentUser = getCurrentUser();
$userId = (int) $currentUser['id'];

$stats = [
    'tests_count' => 0,
    'published_count' => 0,
    'draft_count' => 0,
    'questions_count' => 0,
    'results_count' => 0,
    'average_score' => 0,
];

$statsSql = "SELECT
    COUNT(DISTINCT t.id) AS tests_count,
    COUNT(DISTINCT CASE WHEN t.visibility_status = 'published' THEN t.id END) AS published_count,
    COUNT(DISTINCT CASE WHEN t.visibility_status = 'draft' THEN t.id END) AS draft_count,
    COUNT(DISTINCT q.id) AS questions_count,
    COUNT(DISTINCT r.id) AS results_count,
    COALESCE(ROUND(AVG(CASE WHEN r.total_questions > 0 THEN (r.score / r.total_questions) * 100 END), 0), 0) AS average_score
    FROM tests t
    LEFT JOIN questions q ON q.test_id = t.id
    LEFT JOIN results r ON r.test_id = t.id
    WHERE t.created_by = ?";
$statsStmt = $conn->prepare($statsSql);

if ($statsStmt === false) {
    die('Ошибка подготовки статистики: ' . $conn->error);
}

$statsStmt->bind_param('i', $userId);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc() ?: $stats;

$testsStmt = $conn->prepare(
    'SELECT t.id, t.title, t.subject, t.time_limit_minutes, t.visibility_status,
            COUNT(DISTINCT q.id) AS questions_count
     FROM tests t
     LEFT JOIN questions q ON q.test_id = t.id
     WHERE t.created_by = ?
     GROUP BY t.id, t.title, t.subject, t.time_limit_minutes, t.visibility_status
     ORDER BY t.created_at DESC, t.id DESC
     LIMIT 6'
);

if ($testsStmt === false) {
    die('Ошибка получения тестов преподавателя: ' . $conn->error);
}

$testsStmt->bind_param('i', $userId);
$testsStmt->execute();
$tests = $testsStmt->get_result();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Кабинет преподавателя</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="page">
    <section class="topbar panel panel-compact">
        <div class="topbar-status">
            <span>Преподаватель: <strong><?php echo htmlspecialchars($currentUser['name']); ?></strong></span>
        </div>
        <div class="topbar-actions">
            <a class="button button-secondary" href="create_test.php">Создать тест</a>
            <a class="button button-secondary" href="my_tests.php">Мои тесты</a>
            <a class="button button-secondary" href="tests.php">Опубликованные тесты</a>
            <a class="button button-secondary" href="logout.php">Выйти</a>
        </div>
    </section>

    <section class="page-header">
        <div>
            <p class="eyebrow">Панель преподавателя</p>
            <h1>Кабинет преподавателя</h1>
            <p class="lead">Здесь собрана статистика по вашим тестам, черновикам и опубликованным материалам, а также быстрые ссылки на управление ими.</p>
        </div>
    </section>

    <section class="stats-grid">
        <article class="panel stat-card">
            <p class="card-label">Всего тестов</p>
            <strong><?php echo (int) $stats['tests_count']; ?></strong>
        </article>
        <article class="panel stat-card">
            <p class="card-label">Опубликовано</p>
            <strong><?php echo (int) $stats['published_count']; ?></strong>
        </article>
        <article class="panel stat-card">
            <p class="card-label">Черновики</p>
            <strong><?php echo (int) $stats['draft_count']; ?></strong>
        </article>
        <article class="panel stat-card">
            <p class="card-label">Всего вопросов</p>
            <strong><?php echo (int) $stats['questions_count']; ?></strong>
        </article>
        <article class="panel stat-card">
            <p class="card-label">Прохождений</p>
            <strong><?php echo (int) $stats['results_count']; ?></strong>
        </article>
        <article class="panel stat-card">
            <p class="card-label">Средний результат</p>
            <strong><?php echo (int) $stats['average_score']; ?>%</strong>
        </article>
    </section>

    <section class="actions">
        <a class="button button-primary" href="create_test.php">Создать новый тест</a>
        <a class="button button-secondary" href="my_tests.php">Управлять моими тестами</a>
        <a class="button button-secondary" href="tests.php">Посмотреть каталог опубликованных тестов</a>
    </section>

    <section class="cards-grid">
        <?php while ($test = $tests->fetch_assoc()): ?>
            <article class="panel test-card">
                <p class="card-label">Тест №<?php echo (int) $test['id']; ?></p>
                <h2><?php echo htmlspecialchars($test['title']); ?></h2>
                <p class="meta">Предмет: <?php echo htmlspecialchars($test['subject'] ?: 'Не указан'); ?></p>
                <p class="meta">Вопросов: <?php echo (int) $test['questions_count']; ?></p>
                <p class="meta">Таймер: <?php echo (int) $test['time_limit_minutes'] > 0 ? (int) $test['time_limit_minutes'] . ' мин.' : 'без ограничения'; ?></p>
                <p class="meta">Статус:
                    <span class="status-badge <?php echo $test['visibility_status'] === 'published' ? 'status-published' : 'status-draft'; ?>">
                        <?php echo $test['visibility_status'] === 'published' ? 'Опубликован' : 'Черновик'; ?>
                    </span>
                </p>
            </article>
        <?php endwhile; ?>
    </section>
</main>
</body>
</html>