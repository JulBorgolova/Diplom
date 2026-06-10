<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function getCurrentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function isUserLoggedIn(): bool
{
    return isset($_SESSION['user']);
}

function requireLogin(): void
{
    if (!isUserLoggedIn()) {
        $_SESSION['flash_error'] = 'Сначала войдите в систему, чтобы продолжить работу.';
        header('Location: login.php');
        exit;
    }
}

function isTeacher(): bool
{
    return isset($_SESSION['user']) && in_array($_SESSION['user']['role'], ['teacher', 'admin'], true);
}

function requireTeacher(): void
{
    requireLogin();

    if (!isTeacher()) {
        $_SESSION['flash_error'] = 'Доступ разрешен только преподавателю.';
        header('Location: tests.php');
        exit;
    }
}

function isAdmin(): bool
{
    return isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin';
}

function requireAdmin(): void
{
    requireLogin();

    if (!isAdmin()) {
        $_SESSION['flash_error'] = 'Доступ разрешен только администратору.';
        header('Location: index.php');
        exit;
    }
}

function redirectIfLoggedIn(string $fallback = 'index.php'): void
{
    if (isUserLoggedIn()) {
        header('Location: ' . $fallback);
        exit;
    }
}

function setFlashMessage(string $type, string $message): void
{
    $_SESSION['flash_' . $type] = $message;
}

function pullFlashMessage(string $type): ?string
{
    $key = 'flash_' . $type;

    if (!isset($_SESSION[$key])) {
        return null;
    }

    $message = $_SESSION[$key];
    unset($_SESSION[$key]);

    return $message;
}
?>
