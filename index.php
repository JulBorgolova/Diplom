<?php
require_once __DIR__ . '/config/auth.php';

$siteName = 'АкадемТест';
$currentUser = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($siteName); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="page hero-page">
    <section class="hero-card">
        <div class="topbar">
            <div class="topbar-status">
                <?php if ($currentUser): ?>
                    <span>Вы вошли как <strong><?php echo htmlspecialchars($currentUser['name']); ?></strong></span>
                <?php else: ?>
                    <span>Гость</span>
                <?php endif; ?>
            </div>
            <div class="topbar-actions">
                <a class="button button-secondary" href="tests.php">Тесты</a>
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
        </div>

        <p class="eyebrow">Образовательная платформа</p>
        <h1><?php echo htmlspecialchars($siteName); ?></h1>
        <p class="lead">
            Сайт для создания, публикации и прохождения тестов с поддержкой шести типов вопросов,
            таймера, личного кабинета преподавателя, результатов учеников и административного управления.
        </p>

        <div class="actions">
            <a class="button button-primary" href="tests.php">Открыть каталог тестов</a>
            <?php if ($currentUser): ?>
                <a class="button button-secondary" href="my_results.php">Мои результаты</a>
                <?php if (isTeacher()): ?>
                    <a class="button button-secondary" href="create_test.php">Создать тест</a>
                <?php endif; ?>
            <?php else: ?>
                <a class="button button-secondary" href="login.php">Войти в систему</a>
            <?php endif; ?>
        </div>

        <div class="feature-grid">
            <article class="feature-card">
                <h2>Публикация и черновики</h2>
                <p>Преподаватель сам решает, сразу опубликовать тест или оставить его в личном кабинете как черновик.</p>
            </article>
            <article class="feature-card">
                <h2>6 типов вопросов</h2>
                <p>Да/Нет, один ответ, несколько ответов, последовательность, соответствие и свободный письменный ответ.</p>
            </article>
            <article class="feature-card">
                <h2>Экспорт тестов</h2>
                <p>Готовые тесты можно выгружать в JSON-файл для переноса, архива или демонстрации.</p>
            </article>
        </div>

        <section class="panel" style="margin-top: 28px;">
            <p class="eyebrow">Поддерживаемые типы заданий</p>
            <div class="cards-grid">
                <article class="panel test-card"><h2>Альтернативный выбор</h2><p>Ответы «Да» или «Нет».</p></article>
                <article class="panel test-card"><h2>Один правильный ответ</h2><p>Классический выбор одного варианта.</p></article>
                <article class="panel test-card"><h2>Несколько правильных ответов</h2><p>Можно отметить сразу несколько верных вариантов.</p></article>
                <article class="panel test-card"><h2>Последовательность</h2><p>Нужно указать правильный порядок элементов.</p></article>
                <article class="panel test-card"><h2>Соответствие</h2><p>Необходимо сопоставить левую и правую части.</p></article>
                <article class="panel test-card"><h2>Свободный ответ</h2><p>Ответ вводится в текстовое поле и проверяется мягким сравнением.</p></article>
            </div>
        </section>
    </section>
</main>
</body>
</html>