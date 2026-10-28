<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Course management for the admin (admin/courses.php)
|
| An admin can see every course on the platform, create a course for an
| instructor, edit it, publish or unpublish it, and delete it.
| Needs api/api_assignment_service.php for assessmentDeleteStoredFile().
|--------------------------------------------------------------------------
*/

const ADMIN_COURSE_PER_PAGE = 15;

const ADMIN_COURSE_SORTS = [
    'newest'   => 'c.created_at DESC, c.id DESC',
    'oldest'   => 'c.created_at ASC, c.id ASC',
    'title'    => 'c.title ASC, c.id ASC',
    'enrolled' => 'students DESC, c.title ASC',
    'rated'    => 'rating_count > 0 DESC, rating_average DESC, rating_count DESC, c.title ASC',
];

/* ---------- CSRF (admin forms) ---------- */

function adminCsrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['admin_csrf'];
}

function adminCsrfValid(string $posted): bool
{
    return $posted !== '' && hash_equals(adminCsrfToken(), $posted);
}

function adminCsrfRotate(): void
{
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

/* ---------- Filters ---------- */

/**
 * @return array{q:string,status:string,category:?string,instructor:?int,sort:string,page:int}
 *         category: null = any, 'none' = no category, otherwise the category id as a string
 */
function adminCourseFilters(array $query): array
{
    $status = (string) ($query['status'] ?? 'all');
    $sort   = (string) ($query['sort'] ?? 'newest');
    $page   = filter_var($query['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $instructor = filter_var($query['instructor'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    $category = (string) ($query['category'] ?? '');
    if ($category !== 'none') {
        $parsed = filter_var($category, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $category = $parsed ? (string) $parsed : '';
    }

    return [
        'q'          => mb_substr(trim((string) ($query['q'] ?? '')), 0, 100, 'UTF-8'),
        'status'     => in_array($status, ['all', 'published', 'draft'], true) ? $status : 'all',
        'category'   => $category !== '' ? $category : null,
        'instructor' => $instructor ?: null,
        'sort'       => isset(ADMIN_COURSE_SORTS[$sort]) ? $sort : 'newest',
        'page'       => $page ?: 1,
    ];
}

function adminCourseWhere(array $filters): array
{
    $sql = ' WHERE 1 = 1';
    $params = [];

    if ($filters['q'] !== '') {
        $sql .= ' AND (c.title LIKE :q1 OR u.name LIKE :q2)';
        $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
        $params['q1'] = $like;
        $params['q2'] = $like;
    }
    if ($filters['status'] !== 'all') {
        $sql .= ' AND c.status = :status';
        $params['status'] = $filters['status'];
    }
    if ($filters['category'] === 'none') {
        $sql .= ' AND c.category_id IS NULL';
    } elseif ($filters['category'] !== null) {
        $sql .= ' AND c.category_id = :category';
        $params['category'] = (int) $filters['category'];
    }
    if ($filters['instructor']) {
        $sql .= ' AND c.instructor_id = :instructor';
        $params['instructor'] = $filters['instructor'];
    }

    return [$sql, $params];
}

/* ---------- Reading ---------- */

/** Numbers for the tiles at the top of the page. */
function adminCourseSummary(PDO $pdo): array
{
    $row = $pdo->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(status = 'published'), 0) AS published,
                COALESCE(SUM(status = 'draft'), 0)     AS draft
         FROM courses"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    $enrol = $pdo->query(
        'SELECT COUNT(*) AS enrolments, COUNT(rating) AS ratings, AVG(rating) AS average FROM enrollments'
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total'      => (int) ($row['total'] ?? 0),
        'published'  => (int) ($row['published'] ?? 0),
        'draft'      => (int) ($row['draft'] ?? 0),
        'enrolments' => (int) ($enrol['enrolments'] ?? 0),
        'ratings'    => (int) ($enrol['ratings'] ?? 0),
        'average'    => isset($enrol['average']) && $enrol['average'] !== null ? round((float) $enrol['average'], 2) : null,
    ];
}

/** @return array<int,array{id:int,name:string}> */
function adminCourseCategories(PDO $pdo): array
{
    return $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Instructors for the menus. Inactive accounts are left out unless they are
 * the instructor the course already has.
 */
function adminCourseInstructors(PDO $pdo, ?int $keepId = null): array
{
    $stmt = $pdo->prepare(
        "SELECT id, name, email, status
         FROM users
         WHERE role = 'instructor' AND (status = 'active' OR id = :keep)
         ORDER BY name ASC"
    );
    $stmt->execute(['keep' => $keepId ?? 0]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

const ADMIN_COURSE_SELECT = 'SELECT c.id, c.title, c.description, c.status, c.category_id, c.instructor_id,
        c.created_at, c.updated_at, cat.name AS category,
        u.name AS instructor, u.email AS instructor_email, u.status AS instructor_status,
        (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id)     AS lessons,
        (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS students,
        (SELECT COUNT(*) FROM assignments a WHERE a.course_id = c.id) AS assignments,
        (SELECT COUNT(*) FROM quizzes q WHERE q.course_id = c.id)     AS quizzes,
        (SELECT COUNT(e2.rating) FROM enrollments e2 WHERE e2.course_id = c.id) AS rating_count,
        (SELECT AVG(e3.rating) FROM enrollments e3 WHERE e3.course_id = c.id)   AS rating_average
     FROM courses c
     JOIN users u ON u.id = c.instructor_id
     LEFT JOIN categories cat ON cat.id = c.category_id';

/**
 * One page of courses.
 *
 * @return array{rows:array,total:int,pages:int,page:int}
 */
function adminCourseList(PDO $pdo, array $filters): array
{
    [$where, $params] = adminCourseWhere($filters);

    $count = $pdo->prepare(
        'SELECT COUNT(*) FROM courses c JOIN users u ON u.id = c.instructor_id' . $where
    );
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $pages  = max(1, (int) ceil($total / ADMIN_COURSE_PER_PAGE));
    $page   = min($filters['page'], $pages);
    $offset = ($page - 1) * ADMIN_COURSE_PER_PAGE;

    $stmt = $pdo->prepare(
        ADMIN_COURSE_SELECT . $where
        . ' ORDER BY ' . ADMIN_COURSE_SORTS[$filters['sort']]
        . ' LIMIT ' . ADMIN_COURSE_PER_PAGE . ' OFFSET ' . $offset
    );
    $stmt->execute($params);

    return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'pages' => $pages, 'page' => $page];
}

function adminCourseFind(PDO $pdo, int $courseId): ?array
{
    $stmt = $pdo->prepare(ADMIN_COURSE_SELECT . ' WHERE c.id = :id LIMIT 1');
    $stmt->execute(['id' => $courseId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/* ---------- Writing ---------- */

/**
 * Validate the create and edit forms.
 *
 * @param string[] $errors
 * @param int|null $currentInstructorId the course's present instructor when editing
 * @param int      $lessonCount lessons the course has (a course with none cannot be published)
 * @return array{values:array<string,string>,clean:?array}
 */
function adminCourseValidate(PDO $pdo, array $post, array &$errors, ?int $currentInstructorId, int $lessonCount, bool $isNew): array
{
    $before = count($errors);

    $values = [
        'title'         => trim((string) ($post['title'] ?? '')),
        'description'   => trim((string) ($post['description'] ?? '')),
        'category_id'   => trim((string) ($post['category_id'] ?? '')),
        'instructor_id' => trim((string) ($post['instructor_id'] ?? '')),
        'status'        => $isNew ? 'draft' : (string) ($post['status'] ?? 'draft'),
    ];

    $length = mb_strlen($values['title'], 'UTF-8');
    if ($length < 1 || $length > 190) {
        $errors[] = 'Course title is required and must be 190 characters or fewer.';
    }
    if (mb_strlen($values['description'], 'UTF-8') > 5000) {
        $errors[] = 'Description must be 5000 characters or fewer.';
    }

    $categoryId = null;
    if ($values['category_id'] !== '') {
        $categoryId = filter_var($values['category_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $exists = $categoryId ? $pdo->prepare('SELECT 1 FROM categories WHERE id = :id') : null;
        if ($exists) {
            $exists->execute(['id' => $categoryId]);
        }
        if (!$exists || $exists->fetchColumn() === false) {
            $errors[] = 'Choose a valid category.';
            $categoryId = null;
        }
    }

    $instructorId = filter_var($values['instructor_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$instructorId) {
        $errors[] = 'Choose the instructor who owns this course.';
    } else {
        $stmt = $pdo->prepare("SELECT status FROM users WHERE id = :id AND role = 'instructor' LIMIT 1");
        $stmt->execute(['id' => $instructorId]);
        $status = $stmt->fetchColumn();

        if ($status === false) {
            $errors[] = 'The selected user is not an instructor.';
        } elseif ($status !== 'active' && $instructorId !== $currentInstructorId) {
            $errors[] = 'The selected instructor account is inactive.';
        }
    }

    if (!in_array($values['status'], ['draft', 'published'], true)) {
        $errors[] = 'Choose Draft or Published.';
    } elseif ($values['status'] === 'published' && $lessonCount < 1) {
        $errors[] = 'Add at least one lesson before publishing this course.';
    }

    $clean = null;
    if (count($errors) === $before) {
        $clean = [
            'title'         => $values['title'],
            'description'   => $values['description'] !== '' ? $values['description'] : null,
            'category_id'   => $categoryId ?: null,
            'instructor_id' => (int) $instructorId,
            'status'        => $values['status'],
        ];
    }

    return ['values' => $values, 'clean' => $clean];
}

function adminCourseCreate(PDO $pdo, array $clean): int
{
    $pdo->prepare(
        'INSERT INTO courses (instructor_id, category_id, title, description, status)
         VALUES (:instructor_id, :category_id, :title, :description, :status)'
    )->execute($clean);

    return (int) $pdo->lastInsertId();
}

function adminCourseUpdate(PDO $pdo, int $courseId, array $clean): void
{
    $pdo->prepare(
        'UPDATE courses
         SET instructor_id = :instructor_id, category_id = :category_id,
             title = :title, description = :description, status = :status
         WHERE id = :id'
    )->execute($clean + ['id' => $courseId]);
}

/**
 * Publish or unpublish. Returns an error text, or null when it worked.
 */
function adminCourseSetStatus(PDO $pdo, int $courseId, string $status): ?string
{
    if (!in_array($status, ['draft', 'published'], true)) {
        return 'Choose Draft or Published.';
    }

    if ($status === 'published') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM lessons WHERE course_id = :id');
        $stmt->execute(['id' => $courseId]);
        if ((int) $stmt->fetchColumn() < 1) {
            return 'Add at least one lesson before publishing this course.';
        }
    }

    $pdo->prepare('UPDATE courses SET status = :status WHERE id = :id')
        ->execute(['status' => $status, 'id' => $courseId]);

    return null;
}

/**
 * Delete a course. The database removes its lessons, modules, enrolments,
 * assignments, submissions, quizzes, attempts and cohorts. The uploaded files
 * of those rows are collected first and removed after the delete succeeds.
 */
function adminCourseDelete(PDO $pdo, int $courseId): bool
{
    $files = [];
    $collect = static function (string $sql) use ($pdo, $courseId, &$files): void {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $courseId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
            if (is_string($path) && $path !== '') {
                $files[$path] = true;
            }
        }
    };

    $collect('SELECT thumbnail FROM courses WHERE id = :id');
    $collect('SELECT file_path FROM lessons WHERE course_id = :id');
    $collect('SELECT pdf_path FROM lessons WHERE course_id = :id');
    $collect('SELECT powerpoint_path FROM lessons WHERE course_id = :id');
    $collect('SELECT file_path FROM assignments WHERE course_id = :id');
    $collect('SELECT s.file_path FROM submissions s JOIN assignments a ON a.id = s.assignment_id WHERE a.course_id = :id');

    $delete = $pdo->prepare('DELETE FROM courses WHERE id = :id');
    $delete->execute(['id' => $courseId]);

    if ($delete->rowCount() === 0) {
        return false;
    }

    foreach (array_keys($files) as $path) {
        assessmentDeleteStoredFile($path);
    }

    return true;
}

/** Every instructor account (for the filter menu, so an inactive instructor's courses can still be found). */
function adminCourseAllInstructors(PDO $pdo): array
{
    return $pdo->query("SELECT id, name FROM users WHERE role = 'instructor' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
}
