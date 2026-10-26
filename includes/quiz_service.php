<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Quiz helpers
|--------------------------------------------------------------------------
| Questions are stored as JSON in quizzes.questions, one object per question:
|
|   MCQ   {"id":1,"type":"mcq","text":"...","marks":2,
|          "options":["A","B","C","D"],"correct":2}      (correct = 0-based index)
|
|   Essay {"id":2,"type":"essay","text":"...","marks":10,
|          "model_answer":"..." | null}
|
| When a student answers, compare their chosen option index with "correct".
| Never send "correct" or "model_answer" to the student's browser.
*/

const QUIZ_MAX_QUESTIONS   = 50;
const QUIZ_MIN_OPTIONS     = 4;
const QUIZ_MAX_OPTIONS     = 5;
const QUIZ_CSV_MAX_SIZE    = 1024 * 1024; // 1 MB
const QUIZ_MAX_ERRORS_SHOWN = 12;

/**
 * Validate one raw question and return the clean version, or null when it is
 * invalid (the reasons are appended to $errors).
 *
 * @param array{type?:mixed,text?:mixed,marks?:mixed,options?:mixed,correct?:mixed,model_answer?:mixed} $raw
 * @param string[] $errors
 */
function quizNormalizeQuestion(array $raw, string $label, array &$errors): ?array
{
    $before = count($errors);

    $type = strtolower(trim((string) ($raw['type'] ?? '')));
    if ($type === 'multiple choice' || $type === 'multiple_choice') {
        $type = 'mcq';
    }
    if (!in_array($type, ['mcq', 'essay'], true)) {
        $errors[] = "$label: type must be mcq or essay.";
        return null;
    }

    $text = trim((string) ($raw['text'] ?? ''));
    $textLength = mb_strlen($text, 'UTF-8');
    if ($textLength < 1 || $textLength > 2000) {
        $errors[] = "$label: the question text is required (max 2000 characters).";
    }

    $marks = filter_var(
        trim((string) ($raw['marks'] ?? '')),
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 100]]
    );
    if ($marks === false) {
        $errors[] = "$label: marks must be a whole number from 1 to 100.";
    }

    $question = [
        'type'  => $type,
        'text'  => $text,
        'marks' => $marks === false ? 0 : $marks,
    ];

    if ($type === 'mcq') {
        $rawOptions = is_array($raw['options'] ?? null) ? array_values($raw['options']) : [];
        $options = [];

        foreach ($rawOptions as $position => $option) {
            $option = trim((string) $option);

            // Options 1-4 are required. Option 5 is optional.
            if ($option === '') {
                if ($position < QUIZ_MIN_OPTIONS) {
                    $errors[] = 'MCQ needs at least ' . QUIZ_MIN_OPTIONS . " options: $label option " . ($position + 1) . ' is empty.';
                }
                continue;
            }

            if (mb_strlen($option, 'UTF-8') > 500) {
                $errors[] = "$label: option " . ($position + 1) . ' must be 500 characters or fewer.';
            }

            if ($position >= QUIZ_MAX_OPTIONS) {
                $errors[] = "$label: an MCQ can have at most " . QUIZ_MAX_OPTIONS . ' options.';
                break;
            }

            $options[] = $option;
        }

        if (count($options) < QUIZ_MIN_OPTIONS && count($errors) === $before) {
            $errors[] = "$label: an MCQ needs " . QUIZ_MIN_OPTIONS . ' to ' . QUIZ_MAX_OPTIONS . ' options.';
        }

        $lowered = array_map(static fn (string $o): string => mb_strtolower($o, 'UTF-8'), $options);
        if (count($lowered) !== count(array_unique($lowered))) {
            $errors[] = "$label: two options have the same text.";
        }

        // Correct answer: 1-5 or A-E (CSV), or a 0-based index (manual form).
        $correct = null;
        $rawCorrect = trim((string) ($raw['correct'] ?? ''));

        if (!empty($raw['correct_is_index']) && ctype_digit($rawCorrect)) {
            $correct = (int) $rawCorrect;
        } elseif (ctype_digit($rawCorrect)) {
            $correct = (int) $rawCorrect - 1;
        } elseif (preg_match('/^[A-Ea-e]$/', $rawCorrect) === 1) {
            $correct = ord(strtoupper($rawCorrect)) - ord('A');
        }

        if ($correct === null || $correct < 0 || $correct >= count($options)) {
            $errors[] = "$label: choose which option is the correct answer.";
        } else {
            $question['correct'] = $correct;
        }

        $question['options'] = $options;
    } else {
        $modelAnswer = trim((string) ($raw['model_answer'] ?? ''));
        if (mb_strlen($modelAnswer, 'UTF-8') > 5000) {
            $errors[] = "$label: the model answer must be 5000 characters or fewer.";
        }
        $question['model_answer'] = $modelAnswer !== '' ? $modelAnswer : null;
    }

    return count($errors) === $before ? $question : null;
}

/**
 * Validate the questions typed into the manual form ($_POST['quiz_questions']).
 *
 * @return array<int,array<string,mixed>> clean questions with ids 1..n
 */
function quizBuildFromManual(mixed $posted, array &$errors): array
{
    if (!is_array($posted) || $posted === []) {
        $errors[] = 'Add at least one question.';
        return [];
    }

    if (count($posted) > QUIZ_MAX_QUESTIONS) {
        $errors[] = 'A quiz can have at most ' . QUIZ_MAX_QUESTIONS . ' questions.';
        return [];
    }

    $questions = [];
    $number = 0;

    foreach ($posted as $raw) {
        $number++;
        if (!is_array($raw)) {
            $errors[] = "Question $number is invalid.";
            continue;
        }

        $raw['correct_is_index'] = true;
        $question = quizNormalizeQuestion($raw, "Question $number", $errors);
        if ($question !== null) {
            $questions[] = ['id' => $number] + $question;
        }
    }

    return $errors ? [] : $questions;
}

/**
 * Read a CSV file. Expected header (case-insensitive):
 *
 *   type,question,marks,option1,option2,option3,option4,option5,correct,model_answer
 *
 * option5 and model_answer columns are optional.
 *
 * @return array<int,array<string,mixed>> clean questions with ids 1..n
 */
function quizBuildFromCsv(string $path, array &$errors): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        $errors[] = 'The CSV file could not be read.';
        return [];
    }

    // Excel adds a BOM to UTF-8 CSV files and may use ; as the separator.
    $firstLine = (string) fgets($handle);
    rewind($handle);
    if (str_starts_with($firstLine, "\xEF\xBB\xBF")) {
        fseek($handle, 3);
        $firstLine = substr($firstLine, 3);
    }
    $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    $header = fgetcsv($handle, 0, $delimiter, '"', '');
    if (!$header) {
        fclose($handle);
        $errors[] = 'The CSV file is empty.';
        return [];
    }

    $columns = [];
    foreach ($header as $index => $name) {
        $key = strtolower(trim(str_replace([' ', '-'], '_', (string) $name)));
        $key = preg_replace('/^option_(\d)$/', 'option$1', $key) ?? $key;
        $columns[$key] = $index;
    }

    $required = ['type', 'question', 'marks', 'option1', 'option2', 'option3', 'option4', 'correct'];
    $missing = array_diff($required, array_keys($columns));
    if ($missing) {
        fclose($handle);
        $errors[] = 'The CSV header is missing: ' . implode(', ', $missing) . '.';
        return [];
    }

    $cell = static function (array $row, string $key) use ($columns): string {
        return isset($columns[$key]) ? trim((string) ($row[$columns[$key]] ?? '')) : '';
    };

    $questions = [];
    $rowNumber = 1;
    $shown = 0;
    $hidden = 0;

    while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
        $rowNumber++;

        // Skip blank lines.
        if (count($row) === 1 && trim((string) $row[0]) === '') {
            continue;
        }

        if (count($questions) >= QUIZ_MAX_QUESTIONS) {
            $errors[] = 'A quiz can have at most ' . QUIZ_MAX_QUESTIONS . ' questions.';
            break;
        }

        $rowErrors = [];
        $question = quizNormalizeQuestion(
            [
                'type'         => $cell($row, 'type'),
                'text'         => $cell($row, 'question'),
                'marks'        => $cell($row, 'marks'),
                'options'      => [
                    $cell($row, 'option1'),
                    $cell($row, 'option2'),
                    $cell($row, 'option3'),
                    $cell($row, 'option4'),
                    $cell($row, 'option5'),
                ],
                'correct'      => $cell($row, 'correct'),
                'model_answer' => $cell($row, 'model_answer'),
            ],
            "CSV row $rowNumber",
            $rowErrors
        );

        if ($question === null) {
            foreach ($rowErrors as $message) {
                if ($shown < QUIZ_MAX_ERRORS_SHOWN) {
                    $errors[] = $message;
                    $shown++;
                } else {
                    $hidden++;
                }
            }
            continue;
        }

        $questions[] = ['id' => count($questions) + 1] + $question;
    }

    fclose($handle);

    if ($hidden > 0) {
        $errors[] = "...and $hidden more problem" . ($hidden === 1 ? '' : 's') . '.';
    }

    if (!$errors && $questions === []) {
        $errors[] = 'The CSV file has no questions.';
    }

    return $errors ? [] : $questions;
}

/** Total marks of a clean question list. */
function quizTotalMarks(array $questions): int
{
    return array_sum(array_map(static fn (array $q): int => (int) $q['marks'], $questions));
}
