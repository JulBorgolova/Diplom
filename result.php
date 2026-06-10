<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/test_helpers.php';

requireLogin();

$testId = filter_input(INPUT_POST, 'test_id', FILTER_VALIDATE_INT);
$singleAnswers = $_POST['single_answers'] ?? [];
$multipleAnswers = $_POST['multiple_answers'] ?? [];
$sequenceAnswers = $_POST['sequence_answers'] ?? [];
$matchingAnswers = $_POST['matching_answers'] ?? [];
$textAnswers = $_POST['text_answers'] ?? [];

if (!$testId) {
    die('Не удалось обработать результат теста.');
}

$currentUser = getCurrentUser();
$userId = (int) $currentUser['id'];

$testStmt = $conn->prepare('SELECT title FROM tests WHERE id = ?');
if ($testStmt === false) {
    die('Ошибка запроса теста: ' . $conn->error);
}
$testStmt->bind_param('i', $testId);
$testStmt->execute();
$testRow = $testStmt->get_result()->fetch_assoc();
$testTitle = $testRow['title'] ?? 'Выбранный тест';

try {
    $questions = loadTestQuestions($conn, $testId);
} catch (Throwable $e) {
    die('Не удалось загрузить вопросы теста: ' . $e->getMessage());
}

if ($questions === []) {
    die('В этом тесте нет вопросов.');
}

function formatSequenceByCorrectOrder(array $answers): string
{
    usort($answers, static fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);
    $parts = [];
    foreach ($answers as $answer) {
        $parts[] = $answer['sort_order'] . ') ' . $answer['answer_text'];
    }
    return implode('; ', $parts);
}

function formatUserSequence(array $submittedOrders, array $answers): string
{
    $items = [];
    foreach ($answers as $answer) {
        $order = isset($submittedOrders[$answer['id']]) ? (int) $submittedOrders[$answer['id']] : 0;
        $items[] = [
            'order' => $order,
            'text' => $answer['answer_text'],
        ];
    }

    usort($items, static function ($left, $right) {
        return $left['order'] <=> $right['order'];
    });

    $parts = [];
    foreach ($items as $item) {
        $parts[] = $item['order'] . ') ' . $item['text'];
    }
    return implode('; ', $parts);
}

function formatMatchingCorrect(array $answers): string
{
    $parts = [];
    foreach ($answers as $answer) {
        $parts[] = $answer['answer_text'] . ' -> ' . $answer['match_text'];
    }
    return implode('; ', $parts);
}

function formatMatchingUser(array $submitted, array $answers): string
{
    $optionsById = [];
    foreach ($answers as $answer) {
        $optionsById[$answer['id']] = $answer['match_text'];
    }

    $parts = [];
    foreach ($answers as $answer) {
        $selectedId = (int) ($submitted[$answer['id']] ?? 0);
        $selectedText = $optionsById[$selectedId] ?? 'не выбрано';
        $parts[] = $answer['answer_text'] . ' -> ' . $selectedText;
    }

    return implode('; ', $parts);
}

$details = [];
$score = 0;

foreach ($questions as $question) {
    $questionId = (int) $question['id'];
    $type = $question['question_type'];
    $isCorrect = false;
    $userAnswerText = 'Ответ не дан';
    $correctAnswerText = '';

    if ($type === 'boolean' || $type === 'single') {
        $userAnswerId = (int) ($singleAnswers[$questionId] ?? 0);
        $correctAnswerId = 0;

        foreach ($question['answers'] as $answer) {
            if ($answer['is_correct'] === 1) {
                $correctAnswerId = (int) $answer['id'];
                $correctAnswerText = (string) $answer['answer_text'];
            }
            if ((int) $answer['id'] === $userAnswerId) {
                $userAnswerText = (string) $answer['answer_text'];
            }
        }

        $isCorrect = $userAnswerId > 0 && $userAnswerId === $correctAnswerId;
    } elseif ($type === 'multiple') {
        $selectedIds = array_map('intval', $multipleAnswers[$questionId] ?? []);
        sort($selectedIds);

        $correctIds = [];
        $selectedTexts = [];
        $correctTexts = [];

        foreach ($question['answers'] as $answer) {
            $answerId = (int) $answer['id'];
            if ($answer['is_correct'] === 1) {
                $correctIds[] = $answerId;
                $correctTexts[] = $answer['answer_text'];
            }
            if (in_array($answerId, $selectedIds, true)) {
                $selectedTexts[] = $answer['answer_text'];
            }
        }

        sort($correctIds);
        $isCorrect = $selectedIds === $correctIds;
        $userAnswerText = $selectedTexts !== [] ? implode(', ', $selectedTexts) : 'Ответ не выбран';
        $correctAnswerText = implode(', ', $correctTexts);
    } elseif ($type === 'sequence') {
        $submittedOrders = $sequenceAnswers[$questionId] ?? [];
        $expectedOrders = array_map(static fn ($answer) => (int) $answer['sort_order'], $question['answers']);
        sort($expectedOrders);

        $receivedOrders = [];
        foreach ($question['answers'] as $answer) {
            $receivedOrders[] = (int) ($submittedOrders[$answer['id']] ?? 0);
        }

        $sortedReceived = $receivedOrders;
        sort($sortedReceived);
        $isCorrect = $sortedReceived === $expectedOrders;

        if ($isCorrect) {
            foreach ($question['answers'] as $answer) {
                if ((int) ($submittedOrders[$answer['id']] ?? 0) !== (int) $answer['sort_order']) {
                    $isCorrect = false;
                    break;
                }
            }
        }

        $userAnswerText = formatUserSequence($submittedOrders, $question['answers']);
        $correctAnswerText = formatSequenceByCorrectOrder($question['answers']);
    } elseif ($type === 'matching') {
        $submittedPairs = $matchingAnswers[$questionId] ?? [];
        $isCorrect = true;

        foreach ($question['answers'] as $answer) {
            $selectedId = (int) ($submittedPairs[$answer['id']] ?? 0);
            if ($selectedId !== (int) $answer['id']) {
                $isCorrect = false;
            }
        }

        $userAnswerText = formatMatchingUser($submittedPairs, $question['answers']);
        $correctAnswerText = formatMatchingCorrect($question['answers']);
    } else {
        $userText = trim((string) ($textAnswers[$questionId] ?? ''));
        $correctText = trim((string) ($question['answers'][0]['answer_text'] ?? ''));
        $isCorrect = isTextAnswerCorrect($userText, $correctText);
        $userAnswerText = $userText === '' ? 'Ответ не дан' : $userText;
        $correctAnswerText = $correctText;
    }

    if ($isCorrect) {
        $score++;
    }

    $details[] = [
        'question_text' => $question['question_text'],
        'question_type' => getQuestionTypeLabel($type),
        'is_correct' => $isCorrect,
        'user_answer' => $userAnswerText,
        'correct_answer' => $correctAnswerText,
    ];
}

$totalQuestions = count($questions);
$percentage = $totalQuestions > 0 ? round(($score / $totalQuestions) * 100) : 0;

$insertStmt = $conn->prepare(
    'INSERT INTO results (user_id, test_id, score, total_questions) VALUES (?, ?, ?, ?)'
);
if ($insertStmt === false) {
    die('Ошибка сохранения результата: ' . $conn->error);
}
$insertStmt->bind_param('iiii', $userId, $testId, $score, $totalQuestions);
$insertStmt->execute();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Результат теста</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="page narrow-page">
    <section class="topbar panel panel-compact">
        <div class="topbar-status">
            <span>Результат пользователя: <strong><?php echo htmlspecialchars($currentUser['name']); ?></strong></span>
        </div>
        <div class="topbar-actions">
            <a class="button button-secondary" href="my_results.php">Мои результаты</a>
            <a class="button button-secondary" href="logout.php">Выйти</a>
        </div>
    </section>

    <section class="panel result-card">
        <p class="eyebrow">Результат сохранен</p>
        <h1><?php echo htmlspecialchars($testTitle); ?></h1>
        <div class="result-score">
            <strong><?php echo $score; ?>/<?php echo $totalQuestions; ?></strong>
            <span><?php echo $percentage; ?>% правильных ответов</span>
        </div>
        <div class="actions">
            <a class="button button-primary" href="tests.php">Пройти другой тест</a>
            <a class="button button-secondary" href="my_results.php">Мои результаты</a>
            <a class="button button-secondary" href="index.php">На главную</a>
        </div>
    </section>

    <section class="panel" style="margin-top: 24px;">
        <p class="eyebrow">Разбор ответов</p>
        <h2>Какие ответы были правильные и неправильные</h2>
        <div class="cards-grid">
            <?php foreach ($details as $index => $item): ?>
                <article class="panel test-card">
                    <p class="card-label">Вопрос <?php echo $index + 1; ?></p>
                    <h2><?php echo htmlspecialchars($item['question_text']); ?></h2>
                    <p class="meta">Тип: <?php echo htmlspecialchars($item['question_type']); ?></p>
                    <p>
                        Статус:
                        <strong style="color: <?php echo $item['is_correct'] ? '#166534' : '#b91c1c'; ?>">
                            <?php echo $item['is_correct'] ? 'Верно' : 'Неверно'; ?>
                        </strong>
                    </p>
                    <p>Ваш ответ: <?php echo htmlspecialchars($item['user_answer']); ?></p>
                    <p>Правильный ответ: <?php echo htmlspecialchars($item['correct_answer']); ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>
</body>
</html>