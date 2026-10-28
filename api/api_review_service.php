<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Course reviews (instructor side)
|
| A review is a row of `enrollments` that has a rating (1-5) and an optional
| text. The instructor can read the reviews of their own courses and write one
| reply to each (instructor_reply / replied_at).
|--------------------------------------------------------------------------
*/

const REVIEW_PER_PAGE = 10;

const REVIEW_SORTS = [
    'newest'  => 'COALESCE(e.reviewed_at, e.enrolled_at) DESC, e.id DESC',
    'oldest'  => 'COALESCE(e.reviewed_at, e.enrolled_at) ASC, e.id ASC',
    'highest' => 'e.rating DESC, COALESCE(e.reviewed_at, e.enrolled_at) DESC',
    'lowest'  => 'e.rating ASC, COALESCE(e.reviewed_at, e.enrolled_at) DESC',
];

/**
 * Read and clean the filter form (GET).
 *
 * @return array{course:?int,rating:?int,status:string,comments:bool,sort:string,q:string,page:int}
 */
function reviewFilters(array $query): array
{
    $course = filter_var($query['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $rating = filter_var($query['rating'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
    $page   = filter_var($query['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $status = (string) ($query['status'] ?? 'all');
    $sort   = (string) ($query['sort'] ?? 'newest');

    return [
        'course'   => $course ?: null,
        'rating'   => $rating ?: null,
        'status'   => in_array($status, ['all', 'replied', 'unreplied'], true) ? $status : 'all',
        'comments' => ($query['comments'] ?? '') === '1',
        'sort'     => isset(REVIEW_SORTS[$sort]) ? $sort : 'newest',
        'q'        => mb_substr(trim((string) ($query['q'] ?? '')), 0, 100, 'UTF-8'),
        'page'     => $page ?: 1,
    ];
}

/** Build the WHERE part shared by the summary and the list. */
function reviewWhere(int $instructorId, array $filters, bool $useFilters = true): array
{
    $sql = ' WHERE c.instructor_id = :instructor AND e.rating IS NOT NULL';
    $params = ['instructor' => $instructorId];

    if ($filters['course']) {
        $sql .= ' AND c.id = :course';
        $params['course'] = $filters['course'];
    }

    if ($useFilters) {
        if ($filters['rating']) {
            $sql .= ' AND e.rating = :rating';
            $params['rating'] = $filters['rating'];
        }
        if ($filters['comments']) {
            $sql .= " AND e.review IS NOT NULL AND e.review <> ''";
        }
        if ($filters['status'] === 'replied') {
            $sql .= ' AND e.instructor_reply IS NOT NULL';
        } elseif ($filters['status'] === 'unreplied') {
            $sql .= ' AND e.instructor_reply IS NULL';
        }
        if ($filters['q'] !== '') {
            $sql .= ' AND (e.review LIKE :q1 OR u.name LIKE :q2)';
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $params['q1'] = $like;
            $params['q2'] = $like;
        }
    }

    return [$sql, $params];
}

/**
 * Overall numbers: how many reviews, the average, and how many of each star.
 *
 * @return array{total:int,average:?float,stars:array<int,int>,with_comment:int,unreplied:int}
 */
function reviewSummary(PDO $pdo, int $instructorId, ?int $courseId = null): array
{
    [$where, $params] = reviewWhere($instructorId, ['course' => $courseId], false);

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total, AVG(e.rating) AS average,
                COALESCE(SUM(e.rating = 5), 0) AS s5, COALESCE(SUM(e.rating = 4), 0) AS s4,
                COALESCE(SUM(e.rating = 3), 0) AS s3, COALESCE(SUM(e.rating = 2), 0) AS s2,
                COALESCE(SUM(e.rating = 1), 0) AS s1,
                COALESCE(SUM(e.review IS NOT NULL AND e.review <> \'\'), 0) AS with_comment,
                COALESCE(SUM(e.instructor_reply IS NULL), 0) AS unreplied
         FROM enrollments e
         JOIN courses c ON c.id = e.course_id
         JOIN users u ON u.id = e.user_id' . $where
    );
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total'        => (int) ($row['total'] ?? 0),
        'average'      => isset($row['average']) && $row['average'] !== null ? round((float) $row['average'], 2) : null,
        'stars'        => [5 => (int) ($row['s5'] ?? 0), 4 => (int) ($row['s4'] ?? 0), 3 => (int) ($row['s3'] ?? 0),
                           2 => (int) ($row['s2'] ?? 0), 1 => (int) ($row['s1'] ?? 0)],
        'with_comment' => (int) ($row['with_comment'] ?? 0),
        'unreplied'    => (int) ($row['unreplied'] ?? 0),
    ];
}

/** One line per course: reviews, average rating and reviews still without a reply. */
function reviewCourseStats(PDO $pdo, int $instructorId): array
{
    $stmt = $pdo->prepare(
        'SELECT c.id, c.title,
                COUNT(e.rating) AS reviews,
                AVG(e.rating) AS average,
                COALESCE(SUM(e.rating IS NOT NULL AND e.instructor_reply IS NULL), 0) AS unreplied
         FROM courses c
         LEFT JOIN enrollments e ON e.course_id = c.id
         WHERE c.instructor_id = :instructor
         GROUP BY c.id, c.title
         ORDER BY c.title ASC'
    );
    $stmt->execute(['instructor' => $instructorId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * One page of reviews.
 *
 * @return array{rows:array,total:int,pages:int,page:int}
 */
function reviewList(PDO $pdo, int $instructorId, array $filters): array
{
    [$where, $params] = reviewWhere($instructorId, $filters);
    $from = ' FROM enrollments e
              JOIN courses c ON c.id = e.course_id
              JOIN users u ON u.id = e.user_id';

    $count = $pdo->prepare('SELECT COUNT(*)' . $from . $where);
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $pages = max(1, (int) ceil($total / REVIEW_PER_PAGE));
    $page  = min($filters['page'], $pages);
    $offset = ($page - 1) * REVIEW_PER_PAGE;

    $stmt = $pdo->prepare(
        'SELECT e.id, e.rating, e.review, e.reviewed_at, e.enrolled_at, e.instructor_reply, e.replied_at,
                c.id AS course_id, c.title AS course, u.name AS student'
        . $from . $where
        . ' ORDER BY ' . REVIEW_SORTS[$filters['sort']]
        . ' LIMIT ' . REVIEW_PER_PAGE . ' OFFSET ' . $offset
    );
    $stmt->execute($params);

    return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'pages' => $pages, 'page' => $page];
}

/** A review on one of this instructor's courses, or null. */
function reviewOwned(PDO $pdo, int $enrollmentId, int $instructorId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT e.id, e.course_id, e.instructor_reply
         FROM enrollments e
         JOIN courses c ON c.id = e.course_id
         WHERE e.id = :id AND c.instructor_id = :instructor AND e.rating IS NOT NULL
         LIMIT 1'
    );
    $stmt->execute(['id' => $enrollmentId, 'instructor' => $instructorId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Validate a reply.
 *
 * @param string[] $errors
 */
function reviewValidateReply(string $raw, array &$errors): ?string
{
    $reply = trim($raw);
    $length = mb_strlen($reply, 'UTF-8');

    if ($length < 1 || $length > 2000) {
        $errors[] = 'The reply is required and must be 2000 characters or fewer.';
        return null;
    }

    return $reply;
}

function reviewSaveReply(PDO $pdo, int $enrollmentId, ?string $reply): void
{
    $stmt = $pdo->prepare(
        'UPDATE enrollments
         SET instructor_reply = :reply, replied_at = ' . ($reply === null ? 'NULL' : 'NOW()') . '
         WHERE id = :id'
    );
    $stmt->execute(['reply' => $reply, 'id' => $enrollmentId]);
}
