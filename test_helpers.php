<?php

function getQuestionTypeLabels(): array
{
    return [
        'boolean' => 'Альтернативный выбор: Да / Нет',
        'single' => 'Выбор одного правильного ответа',
        'multiple' => 'Выбор нескольких правильных ответов',
        'sequence' => 'Установка правильной последовательности',
        'matching' => 'Установка соответствия',
        'text' => 'Свободный ответ',
    ];
}

function getQuestionTypeLabel(string $type): string
{
    $labels = getQuestionTypeLabels();
    return $labels[$type] ?? $type;
}

function normalizeTextAnswer(string $text): string
{
    $text = mb_strtolower(trim($text));
    $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

function tokenizeNormalizedAnswer(string $text): array
{
    if ($text === '') {
        return [];
    }

    return array_values(array_filter(explode(' ', $text), static fn ($word) => $word !== ''));
}

function removeServiceWords(array $words): array
{
    $serviceWords = [
        'озеро',
        'река',
        'город',
        'гора',
        'остров',
        'материк',
        'страна',
        'планета',
        'язык',
        'море',
        'океан',
        'полуостров',
        'архипелаг',
        'столица',
        'поселок',
        'село',
        'деревня',
    ];

    return array_values(array_filter(
        $words,
        static fn ($word) => !in_array($word, $serviceWords, true)
    ));
}

function wordsContainAll(array $containerWords, array $requiredWords): bool
{
    if ($containerWords === [] || $requiredWords === []) {
        return false;
    }

    $containerCounts = array_count_values($containerWords);
    $requiredCounts = array_count_values($requiredWords);

    foreach ($requiredCounts as $word => $count) {
        if (($containerCounts[$word] ?? 0) < $count) {
            return false;
        }
    }

    return true;
}

function isTextAnswerCorrect(string $userText, string $correctText): bool
{
    $normalizedUser = normalizeTextAnswer($userText);

    if ($normalizedUser === '') {
        return false;
    }

    $variants = array_map('trim', explode('|', $correctText));

    foreach ($variants as $variant) {
        $normalizedCorrect = normalizeTextAnswer($variant);

        if ($normalizedCorrect === '') {
            continue;
        }

        if ($normalizedUser === $normalizedCorrect) {
            return true;
        }

        $userWords = tokenizeNormalizedAnswer($normalizedUser);
        $correctWords = tokenizeNormalizedAnswer($normalizedCorrect);

        if (wordsContainAll($userWords, $correctWords)) {
            return true;
        }

        $userCoreWords = removeServiceWords($userWords);
        $correctCoreWords = removeServiceWords($correctWords);

        if ($userCoreWords !== [] && $correctCoreWords !== []) {
            if (wordsContainAll($userCoreWords, $correctCoreWords) || wordsContainAll($correctCoreWords, $userCoreWords)) {
                return true;
            }
        }
    }

    return false;
}

function loadTestQuestions(mysqli $conn, int $testId): array
{
    $stmt = $conn->prepare(
        'SELECT q.id AS question_id, q.question_text, q.question_type, q.image_path,
                a.id AS answer_id, a.answer_text, a.is_correct, a.match_text, a.sort_order
         FROM questions q
         LEFT JOIN answers a ON a.question_id = q.id
         WHERE q.test_id = ?
         ORDER BY q.id, a.sort_order, a.id'
    );

    if ($stmt === false) {
        throw new RuntimeException('Ошибка получения вопросов: ' . $conn->error);
    }

    $stmt->bind_param('i', $testId);
    $stmt->execute();
    $result = $stmt->get_result();

    $questions = [];

    while ($row = $result->fetch_assoc()) {
        $questionId = (int) $row['question_id'];

        if (!isset($questions[$questionId])) {
            $questions[$questionId] = [
                'id' => $questionId,
                'question_text' => $row['question_text'],
                'question_type' => $row['question_type'] ?? 'single',
                'image_path' => $row['image_path'],
                'answers' => [],
            ];
        }

        if ($row['answer_id'] !== null) {
            $questions[$questionId]['answers'][] = [
                'id' => (int) $row['answer_id'],
                'answer_text' => $row['answer_text'],
                'is_correct' => (int) $row['is_correct'],
                'match_text' => $row['match_text'],
                'sort_order' => (int) $row['sort_order'],
            ];
        }
    }

    return array_values($questions);
}