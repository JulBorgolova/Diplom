<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

requireAdmin();

$currentUser = getCurrentUser();
$currentUserId = (int) $currentUser['id'];
$errorMessage = null;
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = trim($_POST['role'] ?? '');
        $allowedRoles = ['student', 'teacher', 'admin'];

        if ($userId <= 0 || $name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'Некорректные данные пользователя.';
        } elseif (!in_array($role, $allowedRoles, true)) {
            $errorMessage = 'Некорректная роль пользователя.';
        } elseif ($userId === $currentUserId && $role !== 'admin') {
            $errorMessage = 'Нельзя снять с себя роль администратора.';
        } else {
            $stmt = $conn->prepare('UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?');

            if ($stmt === false) {
                $errorMessage = 'Ошибка подготовки обновления пользователя: ' . $conn->error;
            } else {
                $stmt->bind_param('sssi', $name, $email, $role, $userId);

                if ($stmt->execute()) {
                    $successMessage = 'Данные пользователя обновлены.';

                    if ($userId === $currentUserId) {
                        $_SESSION['user']['name'] = $name;
                        $_SESSION['user']['email'] = $email;
                        $_SESSION['user']['role'] = $role;
                        $currentUser = getCurrentUser();
                    }
                } else {
                    $errorMessage = 'Не удалось обновить пользователя: ' . $stmt->error;
                }
            }
        }
    } elseif ($action === 'delete_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);

        if ($userId <= 0) {
            $errorMessage = 'Некорректный идентификатор пользователя.';
        } elseif ($userId === $currentUserId) {
            $errorMessage = 'Нельзя удалить текущего администратора из собственной сессии.';
        } else {
            $stmt = $conn->prepare('DELETE FROM users WHERE id = ?');

            if ($stmt === false) {
                $errorMessage = 'Ошибка подготовки удаления пользователя: ' . $conn->error;
            } else {
                $stmt->bind_param('i', $userId);

                if ($stmt->execute()) {
                    $successMessage = 'Пользователь удален.';
                } else {
                    $errorMessage = 'Не удалось удалить пользователя.';
                }
            }
        }
    } elseif ($action === 'delete_test') {
        $testId = (int) ($_POST['test_id'] ?? 0);

        if ($testId <= 0) {
            $errorMessage = 'Некорректный идентификатор теста.';
        } else {
            $stmt = $conn->prepare('DELETE FROM tests WHERE id = ?');

            if ($stmt === false) {
                $errorMessage = 'Ошибка подготовки удаления теста: ' . $conn->error;
            } else {
                $stmt->bind_param('i', $testId);

                if ($stmt->execute()) {
                    $successMessage = 'Тест удален.';
                } else {
                    $errorMessage = 'Не удалось удалить тест.';
                }
            }
        }
    } elseif ($action === 'delete_result') {
        $resultId = (int) ($_POST['result_id'] ?? 0);

        if ($resultId <= 0) {
            $errorMessage = 'Некорректный идентификатор результата.';
        } else {
            $stmt = $conn->prepare('DELETE FROM results WHERE id = ?');

            if ($stmt === false) {
                $errorMessage = 'Ошибка подготовки удаления результата: ' . $conn->error;
            } else {
                $stmt->bind_param('i', $resultId);

                if ($stmt->execute()) {
                    $successMessage = 'Результат удален.';
                } else {
                    $errorMessage = 'Не удалось удалить результат.';
                }
            }
        }
    }
}

$summary = [
    'users_count' => 0,
    'teachers_count' => 0,
    'tests_count' => 0,
    'results_count' => 0,
];

$summaryResult = $conn->query(
    "SELECT
        (SELECT COUNT(*) FROM users) AS users_count,
        (SELECT COUNT(*) FROM users WHERE role IN ('teacher', 'admin')) AS teachers_count,
        (SELECT COUNT(*) FROM tests) AS tests_count,
        (SELECT COUNT(*) FROM results) AS results_count"
);

if ($summaryResult) {
    $summary = $summaryResult->fetch_assoc() ?: $summary;
}

$usersResult = $conn->query(
    'SELECT id, name, email, role, created_at
     FROM users
     ORDER BY created_at DESC, id DESC'
);

$testsResult = $conn->query(
    'SELECT t.id, t.title, t.subject, t.time_limit_minutes, t.created_at, u.name AS author_name,
            COUNT(DISTINCT q.id) AS questions_count,
            COUNT(DISTINCT r.id) AS results_count
     FROM tests t
     LEFT JOIN users u ON u.id = t.created_by
     LEFT JOIN questions q ON q.test_id = t.id
     LEFT JOIN results r ON r.test_id = t.id
     GROUP BY t.id, t.title, t.subject, t.time_limit_minutes, t.created_at, u.name
     ORDER BY t.created_at DESC, t.id DESC'
);

$resultsResult = $conn->query(
    'SELECT r.id, r.score, r.total_questions, r.passed_at,
            u.name AS user_name, u.email AS user_email,
            t.title AS test_title
     FROM results r
     INNER JOIN users u ON u.id = r.user_id
     INNER JOIN tests t ON t.id = r.test_id
     ORDER BY r.passed_at DESC, r.id DESC'
);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ-панель</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="page">
    <section class="topbar panel panel-compact">
        <div class="topbar-status">
            <span>Администратор: <strong><?php echo htmlspecialchars($currentUser['name']); ?></strong></span>
        </div>
        <div class="topbar-actions">
            <a class="button button-secondary" href="index.php">На главную</a>
            <a class="button button-secondary" href="teacher_dashboard.php">Кабинет преподавателя</a>
            <a class="button button-secondary" href="tests.php">Тесты</a>
            <a class="button button-secondary" href="logout.php">Выйти</a>
        </div>
    </section>

    <section class="page-header">
        <div>
            <p class="eyebrow">Системное управление</p>
            <h1>Админ-панель</h1>
            <p class="lead">Здесь администратор управляет пользователями, тестами и результатами всей системы.</p>
        </div>
    </section>

    <?php if ($successMessage): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <section class="stats-grid">
        <article class="panel stat-card">
            <p class="card-label">Пользователи</p>
            <strong><?php echo (int) $summary['users_count']; ?></strong>
        </article>
        <article class="panel stat-card">
            <p class="card-label">Преподаватели и админы</p>
            <strong><?php echo (int) $summary['teachers_count']; ?></strong>
        </article>
        <article class="panel stat-card">
            <p class="card-label">Тесты</p>
            <strong><?php echo (int) $summary['tests_count']; ?></strong>
        </article>
        <article class="panel stat-card">
            <p class="card-label">Прохождения</p>
            <strong><?php echo (int) $summary['results_count']; ?></strong>
        </article>
    </section>

    <section class="panel">
        <p class="eyebrow">Пользователи</p>
        <h2>Редактирование пользователей</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Имя</th>
                        <th>Email</th>
                        <th>Роль</th>
                        <th>Дата регистрации</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($usersResult && $usersResult->num_rows > 0): ?>
                        <?php while ($user = $usersResult->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo (int) $user['id']; ?></td>
                                <td>
                                    <form method="post" action="">
                                        <input type="hidden" name="action" value="update_user">
                                        <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                                        <input type="text" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                                </td>
                                <td>
                                        <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                </td>
                                <td>
                                        <select name="role" required>
                                            <option value="student" <?php echo $user['role'] === 'student' ? 'selected' : ''; ?>>student</option>
                                            <option value="teacher" <?php echo $user['role'] === 'teacher' ? 'selected' : ''; ?>>teacher</option>
                                            <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>admin</option>
                                        </select>
                                </td>
                                <td><?php echo htmlspecialchars($user['created_at']); ?></td>
                                <td>
                                        <button class="button button-primary" type="submit">Сохранить</button>
                                    </form>
                                    <form method="post" action="" onsubmit="return confirm('Удалить пользователя?');" style="margin-top:8px;">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                                        <button class="button button-danger" type="submit" <?php echo (int) $user['id'] === $currentUserId ? 'disabled' : ''; ?>>Удалить</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel" style="margin-top: 24px;">
        <p class="eyebrow">Тесты</p>
        <h2>Удаление тестов</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Название</th>
                        <th>Предмет</th>
                        <th>Автор</th>
                        <th>Вопросов</th>
                        <th>Прохождений</th>
                        <th>Дата</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($testsResult && $testsResult->num_rows > 0): ?>
                        <?php while ($test = $testsResult->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo (int) $test['id']; ?></td>
                                <td><?php echo htmlspecialchars($test['title']); ?></td>
                                <td><?php echo htmlspecialchars($test['subject'] ?: 'Не указан'); ?></td>
                                <td><?php echo htmlspecialchars($test['author_name'] ?: 'Не указан'); ?></td>
                                <td><?php echo (int) $test['questions_count']; ?></td>
                                <td><?php echo (int) $test['results_count']; ?></td>
                                <td><?php echo htmlspecialchars($test['created_at']); ?></td>
                                <td>
                                    <a class="button button-secondary" href="test.php?id=<?php echo (int) $test['id']; ?>">Открыть</a>
                                    <form method="post" action="" onsubmit="return confirm('Удалить тест?');" style="margin-top:8px;">
                                        <input type="hidden" name="action" value="delete_test">
                                        <input type="hidden" name="test_id" value="<?php echo (int) $test['id']; ?>">
                                        <button class="button button-danger" type="submit">Удалить</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel" style="margin-top: 24px;">
        <p class="eyebrow">Результаты</p>
        <h2>Просмотр всех результатов</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Пользователь</th>
                        <th>Email</th>
                        <th>Тест</th>
                        <th>Баллы</th>
                        <th>Процент</th>
                        <th>Дата</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($resultsResult && $resultsResult->num_rows > 0): ?>
                        <?php while ($result = $resultsResult->fetch_assoc()): ?>
                            <?php $percentage = (int) $result['total_questions'] > 0 ? round(((int) $result['score'] / (int) $result['total_questions']) * 100) : 0; ?>
                            <tr>
                                <td><?php echo (int) $result['id']; ?></td>
                                <td><?php echo htmlspecialchars($result['user_name']); ?></td>
                                <td><?php echo htmlspecialchars($result['user_email']); ?></td>
                                <td><?php echo htmlspecialchars($result['test_title']); ?></td>
                                <td><?php echo (int) $result['score']; ?>/<?php echo (int) $result['total_questions']; ?></td>
                                <td><?php echo $percentage; ?>%</td>
                                <td><?php echo htmlspecialchars($result['passed_at']); ?></td>
                                <td>
                                    <form method="post" action="" onsubmit="return confirm('Удалить результат?');">
                                        <input type="hidden" name="action" value="delete_result">
                                        <input type="hidden" name="result_id" value="<?php echo (int) $result['id']; ?>">
                                        <button class="button button-danger" type="submit">Удалить</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
  