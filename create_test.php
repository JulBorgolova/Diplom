<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/test_helpers.php';

requireTeacher();

$currentUser = getCurrentUser();
$errorMessage = null;
$successMessage = null;
$questionTypeLabels = getQuestionTypeLabels();

function saveQuestionImage(array $fileData): ?string
{
    if (($fileData['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($fileData['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Не удалось загрузить изображение вопроса.');
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $extension = strtolower(pathinfo((string) ($fileData['name'] ?? ''), PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('Допустимы только изображения JPG, JPEG, PNG, GIF и WEBP.');
    }

    if (($fileData['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Размер изображения не должен превышать 5 МБ.');
    }

    $uploadDir = __DIR__ . '/uploads/questions';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Не удалось создать папку для изображений.');
    }

    $fileName = uniqid('question_', true) . '.' . $extension;
    $targetPath = $uploadDir . '/' . $fileName;

    if (!move_uploaded_file($fileData['tmp_name'], $targetPath)) {
        throw new RuntimeException('Не удалось сохранить изображение вопроса.');
    }

    return 'uploads/questions/' . $fileName;
}

function getUploadedQuestionFile(array $files, int $index): array
{
    return [
        'name' => $files['name'][$index] ?? '',
        'type' => $files['type'][$index] ?? '',
        'tmp_name' => $files['tmp_name'][$index] ?? '',
        'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
        'size' => $files['size'][$index] ?? 0,
    ];
}

function insertQuestionAnswers(mysqli $conn, int $questionId, string $questionType, array $questionData): void
{
    if ($questionType === 'boolean') {
        $correctValue = ($questionData['boolean_correct'] ?? 'yes') === 'no' ? 'no' : 'yes';
        $options = [
            ['text' => 'Да', 'is_correct' => $correctValue === 'yes' ? 1 : 0, 'sort_order' => 1],
            ['text' => 'Нет', 'is_correct' => $correctValue === 'no' ? 1 : 0, 'sort_order' => 2],
        ];

        $stmt = $conn->prepare(
            'INSERT INTO answers (question_id, answer_text, is_correct, sort_order) VALUES (?, ?, ?, ?)'
        );

        if ($stmt === false) {
            throw new RuntimeException('Ошибка подготовки альтернативных ответов: ' . $conn->error);
        }

        foreach ($options as $option) {
            $stmt->bind_param('isii', $questionId, $option['text'], $option['is_correct'], $option['sort_order']);
            $stmt->execute();
        }

        return;
    }

    if ($questionType === 'single') {
        $answers = array_values(array_filter(array_map('trim', $questionData['single_answers'] ?? []), static fn ($item) => $item !== ''));
        $correct = (int) ($questionData['single_correct'] ?? 0);

        if (count($answers) < 2) {
            throw new RuntimeException('У вопроса с одним правильным ответом должно быть минимум 2 варианта.');
        }

        if ($correct < 1 || $correct > count($answers)) {
            throw new RuntimeException('Укажите корректный номер правильного ответа.');
        }

        $stmt = $conn->prepare(
            'INSERT INTO answers (question_id, answer_text, is_correct, sort_order) VALUES (?, ?, ?, ?)'
        );

        if ($stmt === false) {
            throw new RuntimeException('Ошибка подготовки вариантов ответа: ' . $conn->error);
        }

        foreach ($answers as $index => $answerText) {
            $isCorrect = $correct === ($index + 1) ? 1 : 0;
            $sortOrder = $index + 1;
            $stmt->bind_param('isii', $questionId, $answerText, $isCorrect, $sortOrder);
            $stmt->execute();
        }

        return;
    }

    if ($questionType === 'multiple') {
        $answers = $questionData['multiple_answers'] ?? [];
        $cleanAnswers = [];

        foreach ($answers as $answer) {
            $answerText = trim((string) ($answer['text'] ?? ''));
            if ($answerText === '') {
                continue;
            }

            $cleanAnswers[] = [
                'text' => $answerText,
                'is_correct' => isset($answer['is_correct']) ? 1 : 0,
            ];
        }

        if (count($cleanAnswers) < 2) {
            throw new RuntimeException('У вопроса с несколькими правильными ответами должно быть минимум 2 варианта.');
        }

        $correctCount = array_sum(array_column($cleanAnswers, 'is_correct'));
        if ($correctCount < 1) {
            throw new RuntimeException('Для вопроса с несколькими правильными ответами нужно отметить хотя бы один верный вариант.');
        }

        $stmt = $conn->prepare(
            'INSERT INTO answers (question_id, answer_text, is_correct, sort_order) VALUES (?, ?, ?, ?)'
        );

        if ($stmt === false) {
            throw new RuntimeException('Ошибка подготовки вариантов ответа: ' . $conn->error);
        }

        foreach ($cleanAnswers as $index => $answer) {
            $sortOrder = $index + 1;
            $stmt->bind_param('isii', $questionId, $answer['text'], $answer['is_correct'], $sortOrder);
            $stmt->execute();
        }

        return;
    }

    if ($questionType === 'sequence') {
        $items = array_values(array_filter(array_map('trim', $questionData['sequence_items'] ?? []), static fn ($item) => $item !== ''));

        if (count($items) < 2) {
            throw new RuntimeException('Для задания на последовательность нужно минимум 2 элемента.');
        }

        $stmt = $conn->prepare(
            'INSERT INTO answers (question_id, answer_text, is_correct, sort_order) VALUES (?, ?, 1, ?)'
        );

        if ($stmt === false) {
            throw new RuntimeException('Ошибка подготовки элементов последовательности: ' . $conn->error);
        }

        foreach ($items as $index => $item) {
            $sortOrder = $index + 1;
            $stmt->bind_param('isi', $questionId, $item, $sortOrder);
            $stmt->execute();
        }

        return;
    }

    if ($questionType === 'matching') {
        $pairs = $questionData['matching_pairs'] ?? [];
        $cleanPairs = [];

        foreach ($pairs as $pair) {
            $left = trim((string) ($pair['left'] ?? ''));
            $right = trim((string) ($pair['right'] ?? ''));

            if ($left === '' || $right === '') {
                continue;
            }

            $cleanPairs[] = [
                'left' => $left,
                'right' => $right,
            ];
        }

        if (count($cleanPairs) < 2) {
            throw new RuntimeException('Для задания на соответствие нужно минимум 2 пары.');
        }

        $stmt = $conn->prepare(
            'INSERT INTO answers (question_id, answer_text, is_correct, match_text, sort_order) VALUES (?, ?, 1, ?, ?)'
        );

        if ($stmt === false) {
            throw new RuntimeException('Ошибка подготовки пар соответствия: ' . $conn->error);
        }

        foreach ($cleanPairs as $index => $pair) {
            $sortOrder = $index + 1;
            $stmt->bind_param('issi', $questionId, $pair['left'], $pair['right'], $sortOrder);
            $stmt->execute();
        }

        return;
    }

    $textAnswer = trim((string) ($questionData['text_answer'] ?? ''));
    if ($textAnswer === '') {
        throw new RuntimeException('Для свободного ответа нужно указать правильный эталонный ответ.');
    }

    $stmt = $conn->prepare(
        'INSERT INTO answers (question_id, answer_text, is_correct, sort_order) VALUES (?, ?, 1, 1)'
    );

    if ($stmt === false) {
        throw new RuntimeException('Ошибка подготовки письменного ответа: ' . $conn->error);
    }

    $stmt->bind_param('is', $questionId, $textAnswer);
    $stmt->execute();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $visibilityStatus = ($_POST['visibility_status'] ?? 'draft') === 'published' ? 'published' : 'draft';
    $timeLimitMinutes = max(0, (int) ($_POST['time_limit_minutes'] ?? 0));
    $questions = $_POST['questions'] ?? [];
    $questionImages = $_FILES['question_images'] ?? null;

    if ($title === '' || $description === '' || $subject === '') {
        $errorMessage = 'Заполните название, описание и предмет.';
    } elseif (!is_array($questions) || count($questions) === 0) {
        $errorMessage = 'Добавьте хотя бы один вопрос.';
    } else {
        $conn->begin_transaction();
        $savedImages = [];

        try {
            $authorId = (int) $currentUser['id'];
            $testStmt = $conn->prepare(
                'INSERT INTO tests (title, description, subject, time_limit_minutes, visibility_status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );

            if ($testStmt === false) {
                throw new RuntimeException('Ошибка подготовки вставки теста: ' . $conn->error);
            }

            $testStmt->bind_param('sssisi', $title, $description, $subject, $timeLimitMinutes, $visibilityStatus, $authorId);
            $testStmt->execute();
            $testId = $conn->insert_id;

            foreach ($questions as $index => $questionData) {
                $questionText = trim((string) ($questionData['text'] ?? ''));
                $questionType = (string) ($questionData['type'] ?? 'single');

                if ($questionText === '') {
                    throw new RuntimeException('У каждого вопроса должен быть текст.');
                }

                if (!array_key_exists($questionType, $questionTypeLabels)) {
                    throw new RuntimeException('Некорректный тип вопроса.');
                }

                $imagePath = null;
                if ($questionImages !== null) {
                    $imageFile = getUploadedQuestionFile($questionImages, (int) $index);
                    $imagePath = saveQuestionImage($imageFile);
                    if ($imagePath !== null) {
                        $savedImages[] = __DIR__ . '/' . $imagePath;
                    }
                }

                $questionStmt = $conn->prepare(
                    'INSERT INTO questions (test_id, question_text, question_type, image_path) VALUES (?, ?, ?, ?)'
                );

                if ($questionStmt === false) {
                    throw new RuntimeException('Ошибка подготовки вставки вопроса: ' . $conn->error);
                }

                $questionStmt->bind_param('isss', $testId, $questionText, $questionType, $imagePath);
                $questionStmt->execute();
                $questionId = $conn->insert_id;

                insertQuestionAnswers($conn, $questionId, $questionType, $questionData);
            }

            $conn->commit();
            $successMessage = $visibilityStatus === 'published'
                ? 'Тест создан и опубликован.'
                : 'Тест сохранен в личном кабинете как черновик.';
        } catch (Throwable $e) {
            $conn->rollback();
            foreach ($savedImages as $savedImagePath) {
                if (is_file($savedImagePath)) {
                    @unlink($savedImagePath);
                }
            }
            $errorMessage = 'Не удалось создать тест: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Создание теста</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="page narrow-page">
    <section class="topbar panel panel-compact">
        <div class="topbar-status">
            <span>Преподаватель: <strong><?php echo htmlspecialchars($currentUser['name']); ?></strong></span>
        </div>
        <div class="topbar-actions">
            <a class="button button-secondary" href="teacher_dashboard.php">Кабинет преподавателя</a>
            <a class="button button-secondary" href="my_tests.php">Мои тесты</a>
            <a class="button button-secondary" href="logout.php">Выйти</a>
        </div>
    </section>

    <section class="panel">
        <p class="eyebrow">Конструктор тестов</p>
        <h1>Создание нового теста</h1>

        <?php if ($errorMessage): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage); ?></div>
        <?php endif; ?>

        <?php if ($successMessage): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div>
        <?php endif; ?>

        <form class="auth-form" method="post" action="" enctype="multipart/form-data">
            <label class="form-field">
                <span>Название теста</span>
                <input type="text" name="title" required>
            </label>

            <label class="form-field">
                <span>Описание теста</span>
                <textarea name="description" rows="3" required></textarea>
            </label>

            <label class="form-field">
                <span>Предмет</span>
                <input type="text" name="subject" required>
            </label>

            <label class="form-field">
                <span>Лимит времени в минутах</span>
                <input type="number" name="time_limit_minutes" min="0" value="0">
            </label>

            <label class="form-field">
                <span>Статус теста</span>
                <select name="visibility_status">
                    <option value="draft">Оставить в личном кабинете как черновик</option>
                    <option value="published">Сразу опубликовать</option>
                </select>
            </label>

            <div id="questions-container"></div>

            <div class="actions">
                <button class="button button-secondary" type="button" id="add-question-btn">Добавить вопрос</button>
                <button class="button button-primary" type="submit">Сохранить тест</button>
            </div>
        </form>
    </section>
</main>

<script>
const questionTypeOptions = <?php echo json_encode($questionTypeLabels, JSON_UNESCAPED_UNICODE); ?>;

function renumberQuestions() {
    const blocks = document.querySelectorAll('.question-editor');

    blocks.forEach((block, index) => {
        block.dataset.index = index;
        block.querySelector('.question-title').textContent = `Вопрос ${index + 1}`;
        block.querySelector('.question-text').name = `questions[${index}][text]`;
        block.querySelector('.question-type').name = `questions[${index}][type]`;
        block.querySelector('.question-image').name = 'question_images[]';
        block.querySelector('.boolean-correct').name = `questions[${index}][boolean_correct]`;
        block.querySelector('.single-correct').name = `questions[${index}][single_correct]`;
        block.querySelector('.text-answer').name = `questions[${index}][text_answer]`;

        block.querySelectorAll('.single-answer').forEach((input) => {
            input.name = `questions[${index}][single_answers][]`;
        });

        block.querySelectorAll('.multiple-row').forEach((row, answerIndex) => {
            row.querySelector('.multiple-answer-text').name = `questions[${index}][multiple_answers][${answerIndex}][text]`;
            row.querySelector('.multiple-answer-correct').name = `questions[${index}][multiple_answers][${answerIndex}][is_correct]`;
        });

        block.querySelectorAll('.sequence-item').forEach((input) => {
            input.name = `questions[${index}][sequence_items][]`;
        });

        block.querySelectorAll('.matching-row').forEach((row, pairIndex) => {
            row.querySelector('.matching-left').name = `questions[${index}][matching_pairs][${pairIndex}][left]`;
            row.querySelector('.matching-right').name = `questions[${index}][matching_pairs][${pairIndex}][right]`;
        });
    });
}

function toggleQuestionType(block) {
    const type = block.querySelector('.question-type').value;
    block.querySelectorAll('.question-type-section').forEach((section) => {
        section.style.display = section.dataset.type === type ? 'block' : 'none';
    });
}

function addSingleAnswer(block, value = '') {
    const container = block.querySelector('.single-answers-container');
    const count = container.querySelectorAll('.single-answer-row').length + 1;
    const wrapper = document.createElement('label');
    wrapper.className = 'form-field single-answer-row';
    wrapper.innerHTML = `
        <span>Вариант ${count}</span>
        <input class="single-answer" type="text" value="${value.replace(/"/g, '&quot;')}" required>
    `;
    container.appendChild(wrapper);
    renumberQuestions();
}

function addMultipleAnswer(block, text = '', checked = false) {
    const container = block.querySelector('.multiple-answers-container');
    const wrapper = document.createElement('div');
    wrapper.className = 'form-field multiple-row';
    wrapper.innerHTML = `
        <span>Вариант ответа</span>
        <input class="multiple-answer-text" type="text" value="${text.replace(/"/g, '&quot;')}" required>
        <label class="inline-check">
            <input class="multiple-answer-correct" type="checkbox" ${checked ? 'checked' : ''}>
            <span>Правильный вариант</span>
        </label>
    `;
    container.appendChild(wrapper);
    renumberQuestions();
}

function addSequenceItem(block, value = '') {
    const container = block.querySelector('.sequence-items-container');
    const count = container.querySelectorAll('.sequence-item-row').length + 1;
    const wrapper = document.createElement('label');
    wrapper.className = 'form-field sequence-item-row';
    wrapper.innerHTML = `
        <span>Элемент ${count}</span>
        <input class="sequence-item" type="text" value="${value.replace(/"/g, '&quot;')}" required>
    `;
    container.appendChild(wrapper);
    renumberQuestions();
}

function addMatchingPair(block, left = '', right = '') {
    const container = block.querySelector('.matching-pairs-container');
    const wrapper = document.createElement('div');
    wrapper.className = 'matching-row matching-grid';
    wrapper.innerHTML = `
        <label class="form-field">
            <span>Левая часть</span>
            <input class="matching-left" type="text" value="${left.replace(/"/g, '&quot;')}" required>
        </label>
        <label class="form-field">
            <span>Правая часть</span>
            <input class="matching-right" type="text" value="${right.replace(/"/g, '&quot;')}" required>
        </label>
    `;
    container.appendChild(wrapper);
    renumberQuestions();
}

function attachHandlers(block) {
    block.querySelector('.question-type').addEventListener('change', function () {
        toggleQuestionType(block);
    });

    block.querySelector('.remove-question-btn').addEventListener('click', function () {
        const blocks = document.querySelectorAll('.question-editor');

        if (blocks.length === 1) {
            alert('В тесте должен остаться хотя бы один вопрос.');
            return;
        }

        block.remove();
        renumberQuestions();
    });

    block.querySelector('.add-single-answer-btn').addEventListener('click', function () {
        addSingleAnswer(block);
    });

    block.querySelector('.add-multiple-answer-btn').addEventListener('click', function () {
        addMultipleAnswer(block);
    });

    block.querySelector('.add-sequence-item-btn').addEventListener('click', function () {
        addSequenceItem(block);
    });

    block.querySelector('.add-matching-pair-btn').addEventListener('click', function () {
        addMatchingPair(block);
    });
}

function createQuestionBlock(index) {
    const options = Object.entries(questionTypeOptions)
        .map(([value, label]) => `<option value="${value}">${label}</option>`)
        .join('');

    const block = document.createElement('section');
    block.className = 'panel question-editor';
    block.dataset.index = index;
    block.style.marginTop = '20px';

    block.innerHTML = `
        <h2 class="question-title">Вопрос ${index + 1}</h2>

        <label class="form-field">
            <span>Текст вопроса</span>
            <input class="question-text" type="text" required>
        </label>

        <label class="form-field">
            <span>Изображение вопроса (необязательно)</span>
            <input class="question-image" type="file" accept=".jpg,.jpeg,.png,.gif,.webp">
        </label>

        <label class="form-field">
            <span>Тип вопроса</span>
            <select class="question-type">${options}</select>
        </label>

        <div class="question-type-section" data-type="boolean">
            <label class="form-field">
                <span>Правильный ответ</span>
                <select class="boolean-correct">
                    <option value="yes">Да</option>
                    <option value="no">Нет</option>
                </select>
            </label>
        </div>

        <div class="question-type-section" data-type="single" style="display:none;">
            <div class="single-answers-container"></div>
            <div class="actions">
                <button class="button button-secondary add-single-answer-btn" type="button">Добавить вариант</button>
            </div>
            <label class="form-field">
                <span>Номер правильного ответа</span>
                <input class="single-correct" type="number" min="1" value="1" required>
            </label>
        </div>

        <div class="question-type-section" data-type="multiple" style="display:none;">
            <div class="multiple-answers-container"></div>
            <div class="actions">
                <button class="button button-secondary add-multiple-answer-btn" type="button">Добавить вариант</button>
            </div>
        </div>

        <div class="question-type-section" data-type="sequence" style="display:none;">
            <p class="meta">Введите элементы в уже правильном порядке. Ученик будет восстанавливать последовательность при прохождении теста.</p>
            <div class="sequence-items-container"></div>
            <div class="actions">
                <button class="button button-secondary add-sequence-item-btn" type="button">Добавить элемент</button>
            </div>
        </div>

        <div class="question-type-section" data-type="matching" style="display:none;">
            <p class="meta">Заполните пары соответствия: левая часть и правильная правая часть.</p>
            <div class="matching-pairs-container"></div>
            <div class="actions">
                <button class="button button-secondary add-matching-pair-btn" type="button">Добавить пару</button>
            </div>
        </div>

        <div class="question-type-section" data-type="text" style="display:none;">
            <label class="form-field">
                <span>Правильный письменный ответ</span>
                <input class="text-answer" type="text" placeholder="Можно несколько вариантов через |">
            </label>
            <p class="meta">Пример: Байкал|озеро Байкал</p>
        </div>

        <div class="actions">
            <button class="button button-danger remove-question-btn" type="button">Удалить вопрос</button>
        </div>
    `;

    attachHandlers(block);
    addSingleAnswer(block);
    addSingleAnswer(block);
    addMultipleAnswer(block);
    addMultipleAnswer(block);
    addSequenceItem(block);
    addSequenceItem(block);
    addMatchingPair(block);
    addMatchingPair(block);
    toggleQuestionType(block);
    return block;
}

document.getElementById('add-question-btn').addEventListener('click', function () {
    const container = document.getElementById('questions-container');
    const block = createQuestionBlock(container.querySelectorAll('.question-editor').length);
    container.appendChild(block);
    renumberQuestions();
});

document.getElementById('add-question-btn').click();
</script>
</body>
</html>