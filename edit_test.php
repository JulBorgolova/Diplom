<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/test_helpers.php';

requireTeacher();

$currentUser = getCurrentUser();
$userId = (int) $currentUser['id'];
$testId = (int) ($_GET['id'] ?? $_POST['test_id'] ?? 0);
$errorMessage = null;
$successMessage = null;
$questionTypeLabels = getQuestionTypeLabels();

if ($testId <= 0) {
    die('Некорректный идентификатор теста.');
}

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

function transformQuestionsForEditor(array $questions): array
{
    $prepared = [];

    foreach ($questions as $question) {
        $type = $question['question_type'];
        $item = [
            'text' => $question['question_text'],
            'type' => $type,
            'image_path' => $question['image_path'],
            'boolean_correct' => 'yes',
            'single_answers' => ['', ''],
            'single_correct' => 1,
            'multiple_answers' => [
                ['text' => '', 'is_correct' => false],
                ['text' => '', 'is_correct' => false],
            ],
            'sequence_items' => ['', ''],
            'matching_pairs' => [
                ['left' => '', 'right' => ''],
                ['left' => '', 'right' => ''],
            ],
            'text_answer' => '',
        ];

        if ($type === 'boolean') {
            foreach ($question['answers'] as $answer) {
                if ($answer['is_correct'] === 1) {
                    $item['boolean_correct'] = mb_strtolower((string) $answer['answer_text']) === 'нет' ? 'no' : 'yes';
                }
            }
        } elseif ($type === 'single') {
            $item['single_answers'] = [];
            foreach ($question['answers'] as $index => $answer) {
                $item['single_answers'][] = $answer['answer_text'];
                if ($answer['is_correct'] === 1) {
                    $item['single_correct'] = $index + 1;
                }
            }
        } elseif ($type === 'multiple') {
            $item['multiple_answers'] = [];
            foreach ($question['answers'] as $answer) {
                $item['multiple_answers'][] = [
                    'text' => $answer['answer_text'],
                    'is_correct' => $answer['is_correct'] === 1,
                ];
            }
        } elseif ($type === 'sequence') {
            $item['sequence_items'] = array_map(static fn ($answer) => $answer['answer_text'], $question['answers']);
        } elseif ($type === 'matching') {
            $item['matching_pairs'] = [];
            foreach ($question['answers'] as $answer) {
                $item['matching_pairs'][] = [
                    'left' => $answer['answer_text'],
                    'right' => $answer['match_text'],
                ];
            }
        } else {
            $item['text_answer'] = $question['answers'][0]['answer_text'] ?? '';
        }

        $prepared[] = $item;
    }

    return $prepared;
}

$testStmt = $conn->prepare(
    'SELECT id, title, description, subject, time_limit_minutes, visibility_status
     FROM tests
     WHERE id = ? AND created_by = ?
     LIMIT 1'
);

if ($testStmt === false) {
    die('Ошибка получения теста: ' . $conn->error);
}

$testStmt->bind_param('ii', $testId, $userId);
$testStmt->execute();
$test = $testStmt->get_result()->fetch_assoc();

if (!$test) {
    die('Тест не найден или не принадлежит текущему преподавателю.');
}

try {
    $questions = loadTestQuestions($conn, $testId);
    $editorQuestions = transformQuestionsForEditor($questions);
} catch (Throwable $e) {
    die('Не удалось загрузить вопросы теста: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $visibilityStatus = ($_POST['visibility_status'] ?? 'draft') === 'published' ? 'published' : 'draft';
    $timeLimitMinutes = max(0, (int) ($_POST['time_limit_minutes'] ?? 0));
    $postedQuestions = $_POST['questions'] ?? [];
    $questionImages = $_FILES['question_images'] ?? null;
    $existingImagePaths = $_POST['existing_image_path'] ?? [];
    $removeImage = $_POST['remove_image'] ?? [];

    if ($title === '' || $description === '' || $subject === '') {
        $errorMessage = 'Заполните название, описание и предмет.';
    } elseif (!is_array($postedQuestions) || count($postedQuestions) === 0) {
        $errorMessage = 'Добавьте хотя бы один вопрос.';
    } else {
        $conn->begin_transaction();
        $savedImages = [];

        try {
            $updateTestStmt = $conn->prepare(
                'UPDATE tests
                 SET title = ?, description = ?, subject = ?, time_limit_minutes = ?, visibility_status = ?
                 WHERE id = ? AND created_by = ?'
            );

            if ($updateTestStmt === false) {
                throw new RuntimeException('Ошибка подготовки обновления теста: ' . $conn->error);
            }

            $updateTestStmt->bind_param('sssissi', $title, $description, $subject, $timeLimitMinutes, $visibilityStatus, $testId, $userId);
            $updateTestStmt->execute();

            $oldImagePaths = [];
            foreach ($questions as $question) {
                if (!empty($question['image_path'])) {
                    $oldImagePaths[] = $question['image_path'];
                }
            }

            $deleteAnswersStmt = $conn->prepare(
                'DELETE a
                 FROM answers a
                 INNER JOIN questions q ON q.id = a.question_id
                 WHERE q.test_id = ?'
            );

            if ($deleteAnswersStmt === false) {
                throw new RuntimeException('Ошибка подготовки удаления ответов: ' . $conn->error);
            }

            $deleteAnswersStmt->bind_param('i', $testId);
            $deleteAnswersStmt->execute();

            $deleteQuestionsStmt = $conn->prepare('DELETE FROM questions WHERE test_id = ?');

            if ($deleteQuestionsStmt === false) {
                throw new RuntimeException('Ошибка подготовки удаления вопросов: ' . $conn->error);
            }

            $deleteQuestionsStmt->bind_param('i', $testId);
            $deleteQuestionsStmt->execute();

            $usedImagePaths = [];

            foreach ($postedQuestions as $index => $questionData) {
                $questionText = trim((string) ($questionData['text'] ?? ''));
                $questionType = (string) ($questionData['type'] ?? 'single');

                if ($questionText === '') {
                    throw new RuntimeException('У каждого вопроса должен быть текст.');
                }

                if (!array_key_exists($questionType, $questionTypeLabels)) {
                    throw new RuntimeException('Некорректный тип вопроса.');
                }

                $imagePath = trim((string) ($existingImagePaths[$index] ?? ''));
                if (isset($removeImage[$index]) && $removeImage[$index] === '1') {
                    $imagePath = '';
                }

                if ($questionImages !== null) {
                    $imageFile = getUploadedQuestionFile($questionImages, (int) $index);
                    $newImagePath = saveQuestionImage($imageFile);
                    if ($newImagePath !== null) {
                        $imagePath = $newImagePath;
                        $savedImages[] = __DIR__ . '/' . $newImagePath;
                    }
                }

                if ($imagePath !== '') {
                    $usedImagePaths[] = $imagePath;
                } else {
                    $imagePath = null;
                }

                $insertQuestionStmt = $conn->prepare(
                    'INSERT INTO questions (test_id, question_text, question_type, image_path) VALUES (?, ?, ?, ?)'
                );

                if ($insertQuestionStmt === false) {
                    throw new RuntimeException('Ошибка подготовки вставки вопроса: ' . $conn->error);
                }

                $insertQuestionStmt->bind_param('isss', $testId, $questionText, $questionType, $imagePath);
                $insertQuestionStmt->execute();
                $questionId = $conn->insert_id;

                insertQuestionAnswers($conn, $questionId, $questionType, $questionData);
            }

            $conn->commit();

            foreach ($oldImagePaths as $oldImagePath) {
                if (!in_array($oldImagePath, $usedImagePaths, true)) {
                    $fullOldPath = __DIR__ . '/' . $oldImagePath;
                    if (is_file($fullOldPath)) {
                        @unlink($fullOldPath);
                    }
                }
            }

            $successMessage = $visibilityStatus === 'published'
                ? 'Тест обновлен и опубликован.'
                : 'Тест обновлен и сохранен в черновиках.';

            $test['title'] = $title;
            $test['description'] = $description;
            $test['subject'] = $subject;
            $test['time_limit_minutes'] = $timeLimitMinutes;
            $test['visibility_status'] = $visibilityStatus;

            $questions = loadTestQuestions($conn, $testId);
            $editorQuestions = transformQuestionsForEditor($questions);
        } catch (Throwable $e) {
            $conn->rollback();
            foreach ($savedImages as $savedImagePath) {
                if (is_file($savedImagePath)) {
                    @unlink($savedImagePath);
                }
            }
            $errorMessage = 'Не удалось обновить тест: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактирование теста</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="page narrow-page">
    <section class="topbar panel panel-compact">
        <div class="topbar-status">
            <span>Преподаватель: <strong><?php echo htmlspecialchars($currentUser['name']); ?></strong></span>
        </div>
        <div class="topbar-actions">
            <a class="button button-secondary" href="my_tests.php">Мои тесты</a>
            <a class="button button-secondary" href="teacher_dashboard.php">Кабинет преподавателя</a>
            <a class="button button-secondary" href="logout.php">Выйти</a>
        </div>
    </section>

    <section class="panel">
        <p class="eyebrow">Редактирование теста</p>
        <h1><?php echo htmlspecialchars($test['title']); ?></h1>

        <?php if ($successMessage): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage); ?></div>
        <?php endif; ?>

        <form class="auth-form" method="post" action="" enctype="multipart/form-data">
            <input type="hidden" name="test_id" value="<?php echo (int) $testId; ?>">

            <label class="form-field">
                <span>Название теста</span>
                <input type="text" name="title" value="<?php echo htmlspecialchars($test['title']); ?>" required>
            </label>

            <label class="form-field">
                <span>Описание теста</span>
                <textarea name="description" rows="3" required><?php echo htmlspecialchars($test['description']); ?></textarea>
            </label>

            <label class="form-field">
                <span>Предмет</span>
                <input type="text" name="subject" value="<?php echo htmlspecialchars($test['subject']); ?>" required>
            </label>

            <label class="form-field">
                <span>Лимит времени в минутах</span>
                <input type="number" name="time_limit_minutes" min="0" value="<?php echo (int) $test['time_limit_minutes']; ?>">
            </label>

            <label class="form-field">
                <span>Статус теста</span>
                <select name="visibility_status">
                    <option value="draft" <?php echo $test['visibility_status'] === 'draft' ? 'selected' : ''; ?>>Черновик</option>
                    <option value="published" <?php echo $test['visibility_status'] === 'published' ? 'selected' : ''; ?>>Опубликован</option>
                </select>
            </label>

            <div id="questions-container">
                <?php foreach ($editorQuestions as $index => $question): ?>
                    <section class="panel question-editor" data-index="<?php echo $index; ?>" style="margin-top: 20px;">
                        <h2 class="question-title">Вопрос <?php echo $index + 1; ?></h2>

                        <label class="form-field">
                            <span>Текст вопроса</span>
                            <input class="question-text" type="text" name="questions[<?php echo $index; ?>][text]" value="<?php echo htmlspecialchars($question['text']); ?>" required>
                        </label>

                        <input type="hidden" class="existing-image-path" name="existing_image_path[]" value="<?php echo htmlspecialchars((string) $question['image_path']); ?>">

                        <?php if (!empty($question['image_path'])): ?>
                            <div class="form-field">
                                <span>Текущее изображение</span>
                                <img src="<?php echo htmlspecialchars($question['image_path']); ?>" alt="Изображение вопроса" style="max-width: 260px; border-radius: 12px; border: 1px solid #d8d2c8;">
                            </div>
                            <label class="form-field">
                                <span>Удалить текущее изображение</span>
                                <input class="remove-image" type="checkbox" name="remove_image[<?php echo $index; ?>]" value="1">
                            </label>
                        <?php endif; ?>

                        <label class="form-field">
                            <span>Новое изображение вопроса (необязательно)</span>
                            <input class="question-image" type="file" name="question_images[]" accept=".jpg,.jpeg,.png,.gif,.webp">
                        </label>

                        <label class="form-field">
                            <span>Тип вопроса</span>
                            <select class="question-type" name="questions[<?php echo $index; ?>][type]">
                                <?php foreach ($questionTypeLabels as $typeValue => $typeLabel): ?>
                                    <option value="<?php echo htmlspecialchars($typeValue); ?>" <?php echo $question['type'] === $typeValue ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($typeLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <div class="question-type-section" data-type="boolean" style="<?php echo $question['type'] === 'boolean' ? '' : 'display:none;'; ?>">
                            <label class="form-field">
                                <span>Правильный ответ</span>
                                <select class="boolean-correct" name="questions[<?php echo $index; ?>][boolean_correct]">
                                    <option value="yes" <?php echo $question['boolean_correct'] === 'yes' ? 'selected' : ''; ?>>Да</option>
                                    <option value="no" <?php echo $question['boolean_correct'] === 'no' ? 'selected' : ''; ?>>Нет</option>
                                </select>
                            </label>
                        </div>

                        <div class="question-type-section" data-type="single" style="<?php echo $question['type'] === 'single' ? '' : 'display:none;'; ?>">
                            <div class="single-answers-container">
                                <?php foreach ($question['single_answers'] as $answerIndex => $answerText): ?>
                                    <label class="form-field single-answer-row">
                                        <span>Вариант <?php echo $answerIndex + 1; ?></span>
                                        <input class="single-answer" type="text" name="questions[<?php echo $index; ?>][single_answers][]" value="<?php echo htmlspecialchars($answerText); ?>" required>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="actions">
                                <button class="button button-secondary add-single-answer-btn" type="button">Добавить вариант</button>
                            </div>
                            <label class="form-field">
                                <span>Номер правильного ответа</span>
                                <input class="single-correct" type="number" name="questions[<?php echo $index; ?>][single_correct]" min="1" value="<?php echo (int) $question['single_correct']; ?>" required>
                            </label>
                        </div>

                        <div class="question-type-section" data-type="multiple" style="<?php echo $question['type'] === 'multiple' ? '' : 'display:none;'; ?>">
                            <div class="multiple-answers-container">
                                <?php foreach ($question['multiple_answers'] as $answerIndex => $answer): ?>
                                    <div class="form-field multiple-row">
                                        <span>Вариант ответа</span>
                                        <input class="multiple-answer-text" type="text" name="questions[<?php echo $index; ?>][multiple_answers][<?php echo $answerIndex; ?>][text]" value="<?php echo htmlspecialchars($answer['text']); ?>" required>
                                        <label class="inline-check">
                                            <input class="multiple-answer-correct" type="checkbox" name="questions[<?php echo $index; ?>][multiple_answers][<?php echo $answerIndex; ?>][is_correct]" <?php echo $answer['is_correct'] ? 'checked' : ''; ?>>
                                            <span>Правильный вариант</span>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="actions">
                                <button class="button button-secondary add-multiple-answer-btn" type="button">Добавить вариант</button>
                            </div>
                        </div>

                        <div class="question-type-section" data-type="sequence" style="<?php echo $question['type'] === 'sequence' ? '' : 'display:none;'; ?>">
                            <p class="meta">Введите элементы в уже правильном порядке.</p>
                            <div class="sequence-items-container">
                                <?php foreach ($question['sequence_items'] as $itemIndex => $itemText): ?>
                                    <label class="form-field sequence-item-row">
                                        <span>Элемент <?php echo $itemIndex + 1; ?></span>
                                        <input class="sequence-item" type="text" name="questions[<?php echo $index; ?>][sequence_items][]" value="<?php echo htmlspecialchars($itemText); ?>" required>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="actions">
                                <button class="button button-secondary add-sequence-item-btn" type="button">Добавить элемент</button>
                            </div>
                        </div>

                        <div class="question-type-section" data-type="matching" style="<?php echo $question['type'] === 'matching' ? '' : 'display:none;'; ?>">
                            <p class="meta">Заполните пары соответствия.</p>
                            <div class="matching-pairs-container">
                                <?php foreach ($question['matching_pairs'] as $pairIndex => $pair): ?>
                                    <div class="matching-row matching-grid">
                                        <label class="form-field">
                                            <span>Левая часть</span>
                                            <input class="matching-left" type="text" name="questions[<?php echo $index; ?>][matching_pairs][<?php echo $pairIndex; ?>][left]" value="<?php echo htmlspecialchars($pair['left']); ?>" required>
                                        </label>
                                        <label class="form-field">
                                            <span>Правая часть</span>
                                            <input class="matching-right" type="text" name="questions[<?php echo $index; ?>][matching_pairs][<?php echo $pairIndex; ?>][right]" value="<?php echo htmlspecialchars($pair['right']); ?>" required>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="actions">
                                <button class="button button-secondary add-matching-pair-btn" type="button">Добавить пару</button>
                            </div>
                        </div>

                        <div class="question-type-section" data-type="text" style="<?php echo $question['type'] === 'text' ? '' : 'display:none;'; ?>">
                            <label class="form-field">
                                <span>Правильный письменный ответ</span>
                                <input class="text-answer" type="text" name="questions[<?php echo $index; ?>][text_answer]" value="<?php echo htmlspecialchars($question['text_answer']); ?>" placeholder="Можно несколько вариантов через |">
                            </label>
                            <p class="meta">Пример: Байкал|озеро Байкал</p>
                        </div>

                        <div class="actions">
                            <button class="button button-danger remove-question-btn" type="button">Удалить вопрос</button>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>

            <div class="actions">
                <button class="button button-secondary" type="button" id="add-question-btn">Добавить вопрос</button>
                <button class="button button-primary" type="submit">Сохранить изменения</button>
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
        block.querySelector('.existing-image-path').name = 'existing_image_path[]';
        block.querySelector('.boolean-correct').name = `questions[${index}][boolean_correct]`;
        block.querySelector('.single-correct').name = `questions[${index}][single_correct]`;
        block.querySelector('.text-answer').name = `questions[${index}][text_answer]`;

        const removeImage = block.querySelector('.remove-image');
        if (removeImage) {
            removeImage.name = `remove_image[${index}]`;
        }

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

        <input type="hidden" class="existing-image-path" value="">

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
            <p class="meta">Введите элементы в уже правильном порядке.</p>
            <div class="sequence-items-container"></div>
            <div class="actions">
                <button class="button button-secondary add-sequence-item-btn" type="button">Добавить элемент</button>
            </div>
        </div>

        <div class="question-type-section" data-type="matching" style="display:none;">
            <p class="meta">Заполните пары соответствия.</p>
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

document.querySelectorAll('.question-editor').forEach((block) => {
    attachHandlers(block);
    toggleQuestionType(block);
});

document.getElementById('add-question-btn').addEventListener('click', function () {
    const container = document.getElementById('questions-container');
    const block = createQuestionBlock(container.querySelectorAll('.question-editor').length);
    container.appendChild(block);
    renumberQuestions();
});

renumberQuestions();
</script>
</body>
</html>