<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

redirectIfLoggedIn('tests.php');

$pageTitle = 'Вход в систему';
$errorMessage = null;
$successMessage = pullFlashMessage('success');
$flashError = pullFlashMessage('error');

if ($flashError) {
    $errorMessage = $flashError;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $errorMessage = 'Введите email и пароль.';
    } else {
        $stmt = $conn->prepare('SELECT id, name, email, password, role FROM users WHERE email = ? LIMIT 1');

        if ($stmt === false) {
            $errorMessage = 'Ошибка подготовки запроса: ' . $conn->error;
        } else {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            if (!$user || !password_verify($password, $user['password'])) {
                $errorMessage = 'Неверный email или пароль.';
            } else {
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                ];

                header('Location: tests.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="page narrow-page">
    <section class="panel">
        <p class="eyebrow">Авторизация</p>
        <h1>Вход в систему</h1>
        <p class="lead">Войдите, чтобы проходить тесты, сохранять результаты и работать с системой в зависимости от вашей роли.</p>

        <?php if ($successMessage): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage); ?></div>
        <?php endif; ?>

        <form class="auth-form" method="post" action="">
            <label class="form-field">
                <span>Email</span>
                <input type="email" name="email" required>
            </label>

            <label class="form-field">
                <span>Пароль</span>
                <input type="password" name="password" required>
            </label>

            <div class="actions">
                <button class="button button-primary" type="submit">Войти</button>
                <a class="button button-secondary" href="register.php">Создать аккаунт</a>
            </div>
        </form>

        <a class="button button-secondary" href="index.php">Вернуться на главную</a>
    </section>
</main>
</body>
</html>
