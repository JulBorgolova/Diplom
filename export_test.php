<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/test_helpers.php';

requireTeacher();

$currentUser = getCurrentUser();
$userId = (int) $currentUser['id'];
$testId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$testId) {
    die('Некорректный идентификатор теста.');
}

$stmt = $conn->prepare(
    'SELECT id, title, description, subject, time_limit_minutes, visibility_status, created_at
     FROM tests
     WHERE id = ? AND created_by = ?
     LIMIT 1'
);

if ($stmt === false) {
    die('Ошибка подготовки экспорта: ' . $conn->error);
}

$stmt->bind_param('ii', $testId, $userId);
$stmt->execute();
$test = $stmt->get_result()->fetch_assoc();

if (!$test) {
    die('Тест не найден или не принадлежит текущему преподавателю.');
}

try {
    $questions = loadTestQuestions($conn, $testId);
} catch (Throwable $e) {
    die('Не удалось загрузить тест для экспорта: ' . $e->getMessage());
}

$export = [
    'site_name' => 'АкадемТест',
    'exported_at' => date('c'),
    'test' => [
        'id' => (int) $test['id'],
        'title' => $test['title'],
        'description' => $test['description'],
        'subject' => $test['subject'],
        'time_limit_minutes' => (int) $test['time_limit_minutes'],
        'visibility_status' => $test['visibility_status'],
        'created_at' => $test['created_at'],
    ],
    'questions' => $questions,
];

$safeTitle = preg_replace('/[^A-Za-z0-9_\-]+/u', '_', (string) $test['title']);

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $safeTitle . '_test.json"');

echo json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);