<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/test_helpers.php';

requireLogin();

$testId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$testId) {
    die('Некорректный идентификатор теста.');
}

$currentUser = getCurrentUser();
$currentUserId = (int) $currentUser['id'];

$testStmt = $conn->prepare(
    'SELECT id, title, description, subject, time_limit_minutes, visibility_status, created_by
     FROM tests
     WHERE id = ?'
);

if ($testStmt === false) {
    die('Ошибка запроса теста: ' . $conn->error);
}

$testStmt->bind_param('i', $testId);
$testStmt->execute();
$test = $testStmt->get_result()->fetch_assoc();

if (!$test) {
    die('Тест не найден.');
}

$canOpenDraft = isTeacher() && ((int) $test['created_by'] === $currentUserId || isAdmin());
if ($test['visibility_status'] !== 'published' && !$canOpenDraft) {
    die('Этот тест еще не опубликован и недоступен для прохождения.');
}

try {
    $questions = loadTestQuestions($conn, $testId);
} catch (Throwable $e) {
    die('Ошибка загрузки вопросов: ' . $e->getMessage());
}

$timeLimitSeconds = max(0, (int) ($test['time_limit_minutes'] ?? 0) * 60);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($test['title']); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="page narrow-page">
    <section class="topbar panel panel-compact">
        <div class="topbar-status">
            <span>Пользователь: <strong><?php echo htmlspecialchars($currentUser['name']); ?></strong></span>
        </div>
        <div class="topbar-actions">
            <?php if ($timeLimitSeconds > 0): ?>
                <span class="timer-pill">Осталось: <strong id="timer-display"></strong></span>
            <?php endif; ?>
            <a class="button button-secondary" href="tests.php">К тестам</a>
            <a class="button button-secondary" href="logout.php">Выйти</a>
        </div>
    </section>

    <section class="page-header">
        <div>
            <p class="eyebrow">Прохождение теста</p>
            <h1><?php echo htmlspecialchars($test['title']); ?></h1>
            <p class="lead"><?php echo htmlspecialchars($test['description'] ?: 'Ответьте на все вопросы и отправьте форму.'); ?></p>
            <p class="meta">Предмет: <?php echo htmlspecialchars($test['subject'] ?: 'Не указан'); ?></p>
            <p class="meta">Статус: <?php echo $test['visibility_status'] === 'published' ? 'опубликован' : 'черновик'; ?></p>
        </div>
    </section>

    <?php if (count($questions) === 0): ?>
        <section class="panel">
            <h2>В этом тесте пока нет вопросов</h2>
            <p>Сначала добавьте вопросы и ответы, затем обновите страницу.</p>
        </section>
    <?php else: ?>
        <form class="panel quiz-form" action="result.php" method="post" id="test-form">
            <input type="hidden" name="test_id" value="<?php echo (int) $test['id']; ?>">

            <?php foreach ($questions as $index => $question): ?>
                <?php
                $questionNumber = $index + 1;
                $typeLabel = getQuestionTypeLabel($question['question_type']);
                $displayAnswers = $question['answers'];

                if (in_array($question['question_type'], ['sequence', 'matching'], true)) {
                    shuffle($displayAnswers);
                }
                ?>
                <fieldset class="question-block">
                    <legend><?php echo $questionNumber; ?>. <?php echo htmlspecialchars($question['question_text']); ?></legend>
                    <p class="meta">Тип вопроса: <?php echo htmlspecialchars($typeLabel); ?></p>

                    <?php if (!empty($question['image_path'])): ?>
                        <div class="form-field" style="margin-bottom: 14px;">
                            <img src="<?php echo htmlspecialchars($question['image_path']); ?>" alt="Изображение к вопросу" style="max-width: 320px; border-radius: 12px; border: 1px solid #d8d2c8;">
                        </div>
                    <?php endif; ?>

                    <?php if ($question['question_type'] === 'boolean' || $question['question_type'] === 'single'): ?>
                        <?php foreach ($question['answers'] as $answer): ?>
                            <label class="answer-option">
                                <input type="radio" name="single_answers[<?php echo (int) $question['id']; ?>]" value="<?php echo (int) $answer['id']; ?>" required>
                                <span><?php echo htmlspecialchars($answer['answer_text']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    <?php elseif ($question['question_type'] === 'multiple'): ?>
                        <?php foreach ($question['answers'] as $answer): ?>
                            <label class="answer-option">
                                <input type="checkbox" name="multiple_answers[<?php echo (int) $question['id']; ?>][]" value="<?php echo (int) $answer['id']; ?>">
                                <span><?php echo htmlspecialchars($answer['answer_text']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    <?php elseif ($question['question_type'] === 'sequence'): ?>
                        <p class="meta">Укажите номер позиции для каждого элемента: 1, 2, 3 и так далее.</p>
                        <?php foreach ($displayAnswers as $answer): ?>
                            <label class="form-field">
                                <span><?php echo htmlspecialchars($answer['answer_text']); ?></span>
                                <input type="number" min="1" max="<?php echo count($question['answers']); ?>" name="sequence_answers[<?php echo (int) $question['id']; ?>][<?php echo (int) $answer['id']; ?>]" required>
                            </label>
                        <?php endforeach; ?>
                    <?php elseif ($question['question_type'] === 'matching'): ?>
                        <?php $matchingOptions = $question['answers']; shuffle($matchingOptions); ?>
                        <p class="meta">Выберите соответствие для каждого пункта из левой колонки.</p>
                        <?php foreach ($displayAnswers as $answer): ?>
                            <label class="form-field">
                                <span><?php echo htmlspecialchars($answer['answer_text']); ?></span>
                                <select name="matching_answers[<?php echo (int) $question['id']; ?>][<?php echo (int) $answer['id']; ?>]" required>
                                    <option value="">Выберите соответствие</option>
                                    <?php foreach ($matchingOptions as $option): ?>
                                        <option value="<?php echo (int) $option['id']; ?>"><?php echo htmlspecialchars((string) $option['match_text']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <label class="form-field">
                            <span>Введите ответ</span>
                            <input type="text" name="text_answers[<?php echo (int) $question['id']; ?>]" required>
                        </label>
                    <?php endif; ?>
                </fieldset>
            <?php endforeach; ?>

            <button class="button button-primary" type="submit">Завершить тест</button>
        </form>
    <?php endif; ?>
</main>

<?php if ($timeLimitSeconds > 0): ?>
<script>
let remainingSeconds = <?php echo $timeLimitSeconds; ?>;
const timerDisplay = document.getElementById('timer-display');
const testForm = document.getElementById('test-form');

function formatSeconds(seconds) {
    const minutes = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
}

function tickTimer() {
    timerDisplay.textContent = formatSeconds(remainingSeconds);

    if (remainingSeconds <= 0) {
        alert('Время на прохождение теста истекло. Ответы будут отправлены автоматически.');
        testForm.submit();
        return;
    }

    remainingSeconds -= 1;
    setTimeout(tickTimer, 1000);
}

tickTimer();
</script>
<?php endif; ?>
</body>
</html>