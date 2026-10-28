<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Lesson comments (instructor side)
|
| Students write comments on lessons (table `comments`). The instructor can read
| the comments on the lessons of their own courses and reply to them. A reply
| is a comment with parent_id set to the comment it answers.
|--------------------------------------------------------------------------
*/

const COMMENT_PER_PAGE = 10;

/**
 * Read and clean the filter form (GET).
 *
 * @return array{course:?int,lesson:?int,status:string,sort:string,q:string,page:int}
 */
function commentFilters(array $query): array
{
    $course = filter_var($query['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $lesson = filter_var($query['lesson_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $page   = filter_var($query['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $status = (string) ($query['status'] ?? 'all');

    return [
        'course' => $course ?: null,
        'lesson' => $lesson ?: null,
        'status' => in_array($status, ['all', 'needs', 'replied'], true) ? $status : 'all',
        'sort'   => ($query['sort'] ?? '') === 'oldest' ? 'oldest' : 'newest',
        'q'      => mb_substr(trim((string) ($query['q'] ?? '')), 0, 100, 'UTF-8'),
        'page'   => $page ?: 1,
    ];
}

/**
 * The comments an instructor can answer: top-level comments, written by
 * someone else, on lessons of the instructor's courses.
 */
const COMMENT_FROM = ' FROM comments cm
                       JOIN lessons l ON l.id = cm.lesson_id
                       JOIN courses c ON c.id = l.course_id
                       JOIN users u ON u.id = cm.user_id';

function commentWhere(int $instructorId, array $filters, bool $useFilters = true): array
{
    $sql = ' WHERE c.instructor_id = :instructor AND cm.parent_id IS NULL AND cm.user_id <> :self';
    $params = ['instructor' => $instructorId, 'self' => $instructorId];

    if ($filters['course']) {
        $sql .= ' AND c.id = :course';
        $params['course'] = $filters['course'];
    }
    if ($filters['lesson']) {
        $sql .= ' AND l.id = :lesson';
        $params['lesson'] = $filters['lesson'];
    }

    if ($useFilters) {
        if ($filters['status'] !== 'all') {
            $sql .= ($filters['status'] === 'needs' ? ' AND NOT EXISTS' : ' AND EXISTS')
                . ' (SELECT 1 FROM comments r WHERE r.parent_id = cm.id AND r.user_id = :replier)';
            $params['replier'] = $instructorId;
        }
        if ($filters['q'] !== '') {
            $sql .= ' AND (cm.body LIKE :q1 OR u.name LIKE :q2)';
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $params['q1'] = $like;
            $params['q2'] = $like;
        }
    }

    return [$sql, $params];
}

/** Lessons of this instructor's courses, for the filter menu. */
function commentLessons(PDO $pdo, int $instructorId): array
{
    $stmt = $pdo->prepare(
        'SELECT l.id, l.title, c.id AS course_id, c.title AS course
         FROM lessons l
         JOIN courses c ON c.id = l.course_id
         WHERE c.instructor_id = :instructor
         ORDER BY c.title ASC, l.position ASC, l.id ASC'
    );
    $stmt->execute(['instructor' => $instructorId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array{total:int,needs:int} */
function commentSummary(PDO $pdo, int $instructorId, array $filters): array
{
    [$where, $params] = commentWhere($instructorId, $filters, false);
    $params['replier'] = $instructorId;

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total,
                COALESCE(SUM(NOT EXISTS (SELECT 1 FROM comments r WHERE r.parent_id = cm.id AND r.user_id = :replier)), 0) AS needs'
        . COMMENT_FROM . $where
    );
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return ['total' => (int) ($row['total'] ?? 0), 'needs' => (int) ($row['needs'] ?? 0)];
}

/**
 * One page of comments, each with its replies.
 *
 * @return array{rows:array,total:int,pages:int,page:int}
 */
function commentList(PDO $pdo, int $instructorId, array $filters): array
{
    [$where, $params] = commentWhere($instructorId, $filters);

    $count = $pdo->prepare('SELECT COUNT(*)' . COMMENT_FROM . $where);
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $pages  = max(1, (int) ceil($total / COMMENT_PER_PAGE));
    $page   = min($filters['page'], $pages);
    $offset = ($page - 1) * COMMENT_PER_PAGE;
    $order  = $filters['sort'] === 'oldest' ? 'cm.created_at ASC, cm.id ASC' : 'cm.created_at DESC, cm.id DESC';

    $stmt = $pdo->prepare(
        'SELECT cm.id, cm.body, cm.created_at, u.name AS student,
                l.id AS lesson_id, l.title AS lesson, c.id AS course_id, c.title AS course'
        . COMMENT_FROM . $where
        . ' ORDER BY ' . $order . ' LIMIT ' . COMMENT_PER_PAGE . ' OFFSET ' . $offset
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Replies of the comments on this page, oldest first.
    $byParent = [];
    if ($rows) {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $marks = implode(',', array_fill(0, count($ids), '?'));

        $replies = $pdo->prepare(
            'SELECT r.id, r.parent_id, r.user_id, r.body, r.created_at, ru.name, ru.role
             FROM comments r
             JOIN users ru ON ru.id = r.user_id
             WHERE r.parent_id IN (' . $marks . ')
             ORDER BY r.created_at ASC, r.id ASC'
        );
        $replies->execute($ids);
        foreach ($replies->fetchAll(PDO::FETCH_ASSOC) as $reply) {
            $byParent[(int) $reply['parent_id']][] = $reply;
        }
    }

    foreach ($rows as &$row) {
        $row['replies'] = $byParent[(int) $row['id']] ?? [];
    }
    unset($row);

    return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
}

/** A comment this instructor may answer (see COMMENT_FROM), or null. */
function commentAnswerable(PDO $pdo, int $commentId, int $instructorId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT cm.id, cm.lesson_id'
        . COMMENT_FROM
        . ' WHERE cm.id = :id AND c.instructor_id = :instructor AND cm.parent_id IS NULL AND cm.user_id <> :self
            LIMIT 1'
    );
    $stmt->execute(['id' => $commentId, 'instructor' => $instructorId, 'self' => $instructorId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** A reply written by this instructor, or null. */
function commentOwnReply(PDO $pdo, int $replyId, int $instructorId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT cm.id'
        . COMMENT_FROM
        . ' WHERE cm.id = :id AND cm.user_id = :self AND cm.parent_id IS NOT NULL AND c.instructor_id = :instructor
            LIMIT 1'
    );
    $stmt->execute(['id' => $replyId, 'self' => $instructorId, 'instructor' => $instructorId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Validate a reply.
 *
 * @param string[] $errors
 */
function commentValidateReply(string $raw, array &$errors): ?string
{
    $reply = trim($raw);
    $length = mb_strlen($reply, 'UTF-8');

    if ($length < 1 || $length > 2000) {
        $errors[] = 'The reply is required and must be 2000 characters or fewer.';
        return null;
    }

    return $reply;
}

function commentAddReply(PDO $pdo, array $parent, int $instructorId, string $body): void
{
    $pdo->prepare(
        'INSERT INTO comments (lesson_id, parent_id, user_id, body)
         VALUES (:lesson, :parent, :user, :body)'
    )->execute([
        'lesson' => (int) $parent['lesson_id'],
        'parent' => (int) $parent['id'],
        'user'   => $instructorId,
        'body'   => $body,
    ]);
}

function commentUpdateReply(PDO $pdo, int $replyId, int $instructorId, string $body): void
{
    $pdo->prepare('UPDATE comments SET body = :body WHERE id = :id AND user_id = :user')
        ->execute(['body' => $body, 'id' => $replyId, 'user' => $instructorId]);
}

function commentDeleteReply(PDO $pdo, int $replyId, int $instructorId): void
{
    $pdo->prepare('DELETE FROM comments WHERE id = :id AND user_id = :user AND parent_id IS NOT NULL')
        ->execute(['id' => $replyId, 'user' => $instructorId]);
}
