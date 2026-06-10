<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

$currentUser = getCurrentUser();
$search = trim($_GET['search'] ?? '');
$subject = trim($_GET['subject'] ?? '');

$subjectsResult = $conn->query(
    "SELECT DISTINCT subject
     FROM tests
     WHERE visibility_status = 'published' AND subject IS NOT NULL AND subject <> ''
     ORDER BY subject ASC"
);

$subjects = [];
if ($subjectsResult) {
    while ($row = $subjectsResult->fetch_assoc()) {
        $subjects[] = $row['subject'];
    }
}

$sql = "SELECT t.id, t.title, t.description, t.subject, t.time_limit_minutes, u.name AS author_name
        FROM tests t
        LEFT JOIN users u ON u.id = t.created_by
        WHERE t.visibility_status = 'published'";
$params = [];
$types = '';

if ($search !== '') {
    $sql .= ' AND (t.title LIKE ? OR t.description LIKE ?)';
    $searchLike = '%' . $search . '%';
    $params[] = $searchLike;
    $params[] = $searchLike;
    $types .= 'ss';
}

if ($subject !== '') {
    $sql .= ' AND t.subject = ?';
    $params[] = $subject;
    $types .= 's';
}

$sql .= ' ORDER BY t.created_at DESC, t.id DESC';

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die('Ошибка получения тестов: ' . $conn->error);
}

if ($params !== []) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Каталог тестов</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="page">
    <section class="topbar panel panel-compact">
        <div class="topbar-status">
            <?php if ($currentUser): ?>
                <span>Пользователь: <strong><?php echo htmlspecialchars($currentUser['name']); ?></strong></span>
            <?php else: ?>
                <span>Вы просматриваете опубликованные тесты как гость</span>
            <?php endif; ?>
        </div>
        <div class="topbar-actions">
            <a class="button button-secondary" href="index.php">На главную</a>
            <?php if ($currentUser): ?>
                <a class="button button-secondary" href="my_results.php">Мои результаты</a>
                <?php if (isTeacher()): ?>
                    <a class="button button-secondary" href="teacher_dashboard.php">Кабинет преподавателя</a>
                <?php endif; ?>
                <?php if (isAdmin()): ?>
                    <a class="button button-secondary" href="admin_panel.php">Админ-панель</a>
                <?php endif; ?>
                <a class="button button-secondary" href="logout.php">Выйти</a>
            <?php else: ?>
                <a class="button button-secondary" href="login.php">Войти</a>
                <a class="button button-primary" href="register.php">Регистрация</a>
            <?php endif; ?>
        </div>
    </section>

    <section class="page-header">
        <div>
            <p class="eyebrow">Каталог тестов</p>
            <h1>Опубликованные образовательные тесты</h1>
            <p class="lead">Здесь показываются только опубликованные тесты. Черновики остаются видны только преподавателю в личном кабинете.</p>
        </div>
    </section>

    <section class="panel">
        <form class="filter-form" method="get" action="">
            <label class="form-field">
                <span>Поиск по названию</span>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Например, Информатика">
            </label>

            <label class="form-field">
                <span>Предмет</span>
                <select name="subject">
                    <option value="">Все предметы</option>
                    <?php foreach ($subjects as $subjectOption): ?>
                        <option value="<?php echo htmlspecialchars($subjectOption); ?>" <?php echo $subject === $subjectOption ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($subjectOption); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="filter-actions">
                <button class="button button-primary" type="submit">Применить</button>
                <a class="button button-secondary" href="tests.php">Сбросить</a>
            </div>
        </form>
    </section>

    <section class="cards-grid" style="margin-top: 24px;">
        <?php if ($result->num_rows > 0): ?>
            <?php while ($test = $result->fetch_assoc()): ?>
                <article class="panel test-card">
                    <p class="card-label">Тест №<?php echo (int) $test['id']; ?></p>
                    <h2><?php echo htmlspecialchars($test['title']); ?></h2>
                    <p><?php echo nl2br(htmlspecialchars($test['description'] ?: 'Описание пока не добавлено.')); ?></p>
                    <p class="meta">Предмет: <?php echo htmlspecialchars($test['subject'] ?: 'Не указан'); ?></p>
                    <p class="meta">Автор: <?php echo htmlspecialchars($test['author_name'] ?: 'Не указан'); ?></p>
                    <p class="meta">Лимит времени: <?php echo (int) $test['time_limit_minutes'] > 0 ? (int) $test['time_limit_minutes'] . ' мин.' : 'без ограничения'; ?></p>
                    <a class="button button-primary" href="test.php?id=<?php echo (int) $test['id']; ?>">Пройти тест</a>
                </article>
            <?php endwhile; ?>
        <?php else: ?>
            <article class="panel empty-state">
                <h2>Пока нет опубликованных тестов</h2>
                <p>Измените условия поиска или попросите преподавателя опубликовать новый тест.</p>
            </article>
        <?php endif; ?>
    </section>
</main>
</body>
</html>