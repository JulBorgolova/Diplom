<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

redirectIfLoggedIn('tests.php');

$pageTitle = 'Регистрация';
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $role = trim($_POST['role'] ?? 'student');

    $allowedRoles = ['student', 'teacher'];

    if ($name === '' || $email === '' || $password === '' || $confirmPassword === '' || $role === '') {
        $errorMessage = 'Заполните все поля формы.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Введите корректный email.';
    } elseif (mb_strlen($password) < 6) {
        $errorMessage = 'Пароль должен содержать минимум 6 символов.';
    } elseif ($password !== $confirmPassword) {
        $errorMessage = 'Пароли не совпадают.';
    } elseif (!in_array($role, $allowedRoles, true)) {
        $errorMessage = 'Некорректная роль пользователя.';
    } else {
        $checkStmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');

        if ($checkStmt === false) {
            $errorMessage = 'Ошибка подготовки запроса: ' . $conn->error;
        } else {
            $checkStmt->bind_param('s', $email);
            $checkStmt->execute();
            $exists = $checkStmt->get_result()->fetch_assoc();

            if ($exists) {
                $errorMessage = 'Пользователь с таким email уже зарегистрирован.';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $insertStmt = $conn->prepare(
                    'INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)'
                );

                if ($insertStmt === false) {
                    $errorMessage = 'Ошибка подготовки запроса: ' . $conn->error;
                } else {
                    $insertStmt->bind_param('ssss', $name, $email, $passwordHash, $role);

                    if ($insertStmt->execute()) {
                        setFlashMessage('success', 'Регистрация завершена. Теперь войдите в систему.');
                        header('Location: login.php');
                        exit;
                    }

                    $errorMessage = 'Не удалось создать пользователя. Попробуйте еще раз.';
                }
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
        <p class="eyebrow">Новый пользователь</p>
        <h1>Регистрация</h1>
        <p class="lead">Создайте аккаунт и выберите роль для работы в системе.</p>

        <?php if ($errorMessage): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage); ?></div>
        <?php endif; ?>

        <form class="auth-form" method="post" action="">
            <label class="form-field">
                <span>ФИО</span>
                <input type="text" name="name" required>
            </label>

            <label class="form-field">
                <span>Email</span>
                <input type="email" name="email" required>
            </label>

            <label class="form-field">
                <span>Роль</span>
                <select name="role" required>
                    <option value="student">Студент</option>
                    <option value="teacher">Преподаватель</option>
                </select>
            </label>

            <label class="form-field">
                <span>Пароль</span>
                <input type="password" name="password" required>
            </label>

            <label class="form-field">
                <span>Повторите пароль</span>
                <input type="password" name="confirm_password" required>
            </label>

            <div class="actions">
                <button class="button button-primary" type="submit">Зарегистрироваться</button>
                <a class="button button-secondary" href="login.php">У меня уже есть аккаунт</a>
            </div>
        </form>

        <a class="button button-secondary" href="index.php">Вернуться на главную</a>
    </section>
</main>
</body>
</html>
