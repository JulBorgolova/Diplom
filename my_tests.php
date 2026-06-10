<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/test_helpers.php';

requireTeacher();

$currentUser = getCurrentUser();
$userId = (int) $currentUser['id'];
$errorMessage = null;
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $testId = (int) ($_POST['test_id'] ?? 0);

    if ($testId <= 0) {
        $errorMessage = 'Некорректный идентификатор теста.';
    } elseif ($action === 'delete') {
        $deleteStmt = $conn->prepare('DELETE FROM tests WHERE id = ? AND created_by = ?');

        if ($deleteStmt === false) {
            $errorMessage = 'Ошибка удаления теста: ' . $conn->error;
        } else {
            $deleteStmt->bind_param('ii', $testId, $userId);
            if ($deleteStmt->execute()) {
                $successMessage = 'Тест удален.';
            } else {
                $errorMessage = 'Не удалось удалить тест.';
            }
        }
    } elseif ($action === 'toggle_visibility') {
        $status = ($_POST['visibility_status'] ?? '') === 'published' ? 'draft' : 'published';
        $updateStmt = $conn->prepare('UPDATE tests SET visibility_status = ? WHERE id = ? AND created_by = ?');

        if ($updateStmt === false) {
            $errorMessage = 'Ошибка смены статуса: ' . $conn->error;
        } else {
            $updateStmt->bind_param('sii', $status, $testId, $userId);
            if ($updateStmt->execute()) {
                $successMessage = $status === 'published' ? 'Тест опубликован.' : 'Тест возвращен в черновики.';
            } else {
                $errorMessage = 'Не удалось изменить статус теста.';
            }
        }
    }
}

$stmt = $conn->prepare(
    'SELECT t.id, t.title, t.description, t.subject, t.time_limit_minutes, t.visibility_status, t.created_at,
            COUNT(DISTINCT q.id) AS questions_count
     FROM tests t
     LEFT JOIN questions q ON q.test_id = t.id
     WHERE t.created_by = ?
     GROUP BY t.id, t.title, t.description, t.subject, t.time_limit_minutes, t.visibility_status, t.created_at
     ORDER BY t.created_at DESC, t.id DESC'
);

if ($stmt === false) {
    die('Ошибка получения списка тестов: ' . $conn->error);
}

$stmt->bind_param('i', $userId);
$stmt->execute();
$tests = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мои тесты</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="page">
    <section class="topbar panel panel-compact">
        <div class="topbar-status">
            <span>Преподаватель: <strong><?php echo htmlspecialchars($currentUser['name']); ?></strong></span>
        </div>
        <div class="topbar-actions">
            <a class="button button-secondary" href="teacher_dashboard.php">Кабинет преподавателя</a>
            <a class="button button-secondary" href="create_test.php">Создать тест</a>
            <a class="button button-secondary" href="logout.php">Выйти</a>
        </div>
    </section>

    <section class="page-header">
        <div>
            <p class="eyebrow">Управление тестами</p>
            <h1>Мои тесты</h1>
            <p class="lead">Здесь вы управляете черновиками и опубликованными тестами, а также можете экспортировать готовый тест в JSON.</p>
        </div>
        <a class="button button-secondary" href="tests.php">Каталог опубликованных тестов</a>
    </section>

    <?php if ($successMessage): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <section class="cards-grid">
        <?php if ($tests->num_rows > 0): ?>
            <?php while ($test = $tests->fetch_assoc()): ?>
                <article class="panel test-card">
                    <p class="card-label">Тест №<?php echo (int) $test['id']; ?></p>
                    <h2><?php echo htmlspecialchars($test['title']); ?></h2>
                    <p><?php echo nl2br(htmlspecialchars($test['description'] ?: 'Описание не добавлено.')); ?></p>
                    <p class="meta">Предмет: <?php echo htmlspecialchars($test['subject'] ?: 'Не указан'); ?></p>
                    <p class="meta">Типов вопросов доступно: 6</p>
                    <p class="meta">Вопросов: <?php echo (int) $test['questions_count']; ?></p>
                    <p class="meta">Таймер: <?php echo (int) $test['time_limit_minutes'] > 0 ? (int) $test['time_limit_minutes'] . ' мин.' : 'без ограничения'; ?></p>
                    <p class="meta">Статус: <span class="status-badge <?php echo $test['visibility_status'] === 'published' ? 'status-published' : 'status-draft'; ?>">
                        <?php echo $test['visibility_status'] === 'published' ? 'Опубликован' : 'Черновик'; ?>
                    </span></p>
                    <p class="meta">Создан: <?php echo htmlspecialchars($test['created_at']); ?></p>

                    <div class="actions">
                        <a class="button button-primary" href="edit_test.php?id=<?php echo (int) $test['id']; ?>">Редактировать</a>
                        <a class="button button-secondary" href="test.php?id=<?php echo (int) $test['id']; ?>">Открыть</a>
                        <a class="button button-secondary" href="export_test.php?id=<?php echo (int) $test['id']; ?>">Экспорт</a>
                    </div>

                    <div class="actions">
                        <form method="post" action="">
                            <input type="hidden" name="action" value="toggle_visibility">
                            <input type="hidden" name="test_id" value="<?php echo (int) $test['id']; ?>">
                            <input type="hidden" name="visibility_status" value="<?php echo htmlspecialchars($test['visibility_status']); ?>">
                            <button class="button button-secondary" type="submit">
                                <?php echo $test['visibility_status'] === 'published' ? 'Убрать в черновики' : 'Опубликовать'; ?>
                            </button>
                        </form>

                        <form method="post" action="" onsubmit="return confirm('Удалить этот тест?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="test_id" value="<?php echo (int) $test['id']; ?>">
                            <button class="button button-danger" type="submit">Удалить</button>
                        </form>
                    </div>
                </article>
            <?php endwhile; ?>
        <?php else: ?>
            <article class="panel empty-state">
                <h2>У вас пока нет тестов</h2>
                <p>Создайте первый тест и решите, опубликовать его или оставить в личном кабинете как черновик.</p>
                <a class="button button-primary" href="create_test.php">Создать тест</a>
            </article>
        <?php endif; ?>
    </section>
</main>
</body>
</html>