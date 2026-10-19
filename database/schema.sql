-- ============================================================
--  LMS DATABASE SCHEMA (SIMPLIFIED: 14 TABLES)
--  Engine: InnoDB | Charset: utf8mb4 | MySQL 8 / MariaDB 10.6+
--
--  How to use:
--    phpMyAdmin -> Import -> choose this file
--    or: mysql -u root -p < database/schema.sql
--    Existing databases: run database/migrations/20261019_add_usernames.sql once.
--
--  What was merged (compared with the 24-table version):
--    email_verifications + password_resets  -> user_tokens
--    login_attempts                         -> users.failed_logins / locked_until
--    reviews                                -> enrollments.rating / review
--    certificates                           -> enrollments.completed_at / certificate_code
--    questions + options                    -> quizzes.questions (JSON)
--    quiz_answers                           -> quiz_attempts.answers (JSON)
--    points_log                             -> users.points (running total)
--    badges                                 -> defined in PHP config; earned ones in user_badges
--    notifications                          -> built from announcements + graded submissions,
--                                              using users.notifications_seen_at
-- ============================================================

CREATE DATABASE IF NOT EXISTS lms_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE lms_db;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. USERS
--    Also holds: points total, login lockout, notification marker
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                  VARCHAR(100)  NOT NULL,
  username              VARCHAR(64)  NULL,
  email                 VARCHAR(190)  NOT NULL,
  password_hash         VARCHAR(255)  NOT NULL,
  role                  ENUM('admin','instructor','student') NOT NULL DEFAULT 'student',
  status                ENUM('active','inactive') NOT NULL DEFAULT 'active',
  photo                 VARCHAR(255)  NULL,
  email_verified_at     DATETIME      NULL,
  points                INT UNSIGNED  NOT NULL DEFAULT 0,        -- gamification total
  failed_logins         TINYINT UNSIGNED NOT NULL DEFAULT 0,     -- reset to 0 on success
  locked_until          DATETIME      NULL,                      -- temporary lock after too many failures
  notifications_seen_at DATETIME      NULL,                      -- bell icon: show items newer than this
  created_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role_status (role, status),
  KEY idx_users_points (points)                                  -- leaderboard
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. USER_TOKENS  (email verification + password reset)
--    Only the SHA-256 hash of the token is stored.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_tokens (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  type        ENUM('verify','reset') NOT NULL,
  token_hash  CHAR(64)     NOT NULL,
  expires_at  DATETIME     NOT NULL,
  used_at     DATETIME     NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,   -- also used to limit emails per hour
  PRIMARY KEY (id),
  UNIQUE KEY uq_token_hash (token_hash),
  KEY idx_tokens_user_type (user_id, type, created_at),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. CATEGORIES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name  VARCHAR(100) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. COURSES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS courses (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  instructor_id  INT UNSIGNED NOT NULL,
  category_id    INT UNSIGNED NULL,
  title          VARCHAR(190) NOT NULL,
  description    TEXT         NULL,
  thumbnail      VARCHAR(255) NULL,
  status         ENUM('draft','published') NOT NULL DEFAULT 'draft',
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_courses_instructor (instructor_id),
  KEY idx_courses_category (category_id),
  KEY idx_courses_status (status),
  FULLTEXT KEY ft_courses_search (title, description),
  CONSTRAINT fk_courses_instructor FOREIGN KEY (instructor_id) REFERENCES users(id)      ON DELETE CASCADE,
  CONSTRAINT fk_courses_category   FOREIGN KEY (category_id)   REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. LESSONS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lessons (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id  INT UNSIGNED NOT NULL,
  title      VARCHAR(190) NOT NULL,
  type       ENUM('text','video','pdf') NOT NULL DEFAULT 'text',
  content    MEDIUMTEXT   NULL,            -- text body, or video URL (YouTube/Vimeo)
  file_path  VARCHAR(255) NULL,            -- uploaded PDF
  position   INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lessons_course_position (course_id, position),
  CONSTRAINT fk_lessons_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. ENROLLMENTS
--    Also holds: the student's review, completion and certificate
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS enrollments (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          INT UNSIGNED NOT NULL,
  course_id        INT UNSIGNED NOT NULL,
  last_lesson_id   INT UNSIGNED NULL,            -- "Continue learning" button
  rating           TINYINT UNSIGNED NULL,        -- review: 1-5 (NULL = not reviewed)
  review           TEXT         NULL,
  reviewed_at      DATETIME     NULL,
  completed_at     DATETIME     NULL,            -- set when progress reaches 100%
  certificate_code CHAR(16)     NULL,            -- set when completed (unique verification code)
  enrolled_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_enrollment (user_id, course_id),           -- one enrollment AND one review per student per course
  UNIQUE KEY uq_certificate_code (certificate_code),       -- NULLs are allowed many times
  KEY idx_enrollments_course (course_id, rating),
  CONSTRAINT chk_enroll_rating CHECK (rating IS NULL OR rating BETWEEN 1 AND 5),
  CONSTRAINT fk_enroll_user   FOREIGN KEY (user_id)        REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT fk_enroll_course FOREIGN KEY (course_id)      REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_enroll_lesson FOREIGN KEY (last_lesson_id) REFERENCES lessons(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. LESSON_PROGRESS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lesson_progress (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  lesson_id     INT UNSIGNED NOT NULL,
  completed_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_progress (user_id, lesson_id),            -- INSERT IGNORE = award points only once
  KEY idx_progress_lesson (lesson_id),
  CONSTRAINT fk_progress_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT fk_progress_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. COMMENTS (on lessons)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS comments (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  lesson_id   INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  body        TEXT         NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_comments_lesson (lesson_id, created_at),
  KEY idx_comments_user (user_id),
  CONSTRAINT fk_comments_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
  CONSTRAINT fk_comments_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 9. QUIZZES
--    Questions and options are stored together as JSON:
--    [
--      {"id":1, "text":"Question?", "marks":1,
--       "options":["A","B","C"], "correct":0},      <- "correct" = index of the right option
--      ...
--    ]
--    SECURITY: never send "correct" to the browser. Grade on the server only.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS quizzes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id     INT UNSIGNED NOT NULL,
  title         VARCHAR(190) NOT NULL,
  pass_mark     TINYINT UNSIGNED NOT NULL DEFAULT 50,   -- percentage
  max_attempts  TINYINT UNSIGNED NOT NULL DEFAULT 3,
  questions     JSON         NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_quizzes_course (course_id),
  CONSTRAINT chk_quizzes_pass CHECK (pass_mark BETWEEN 0 AND 100),
  CONSTRAINT fk_quizzes_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 10. QUIZ_ATTEMPTS
--     answers JSON: [{"q":1,"a":0}, {"q":2,"a":2}]  (question id, chosen option index)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS quiz_attempts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  quiz_id       INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  score         INT UNSIGNED NOT NULL DEFAULT 0,
  total_marks   INT UNSIGNED NOT NULL DEFAULT 0,
  passed        TINYINT(1)   NOT NULL DEFAULT 0,
  answers       JSON         NULL,
  attempted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempts_quiz_user (quiz_id, user_id),
  KEY idx_attempts_user (user_id),
  CONSTRAINT fk_attempts_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
  CONSTRAINT fk_attempts_user FOREIGN KEY (user_id) REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 11. ASSIGNMENTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS assignments (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id    INT UNSIGNED NOT NULL,
  title        VARCHAR(190) NOT NULL,
  description  TEXT         NULL,
  due_date     DATETIME     NOT NULL,
  max_mark     SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_assignments_course (course_id, due_date),
  CONSTRAINT fk_assignments_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 12. SUBMISSIONS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS submissions (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  assignment_id  INT UNSIGNED NOT NULL,
  student_id     INT UNSIGNED NOT NULL,
  file_path      VARCHAR(255) NOT NULL,
  submitted_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  mark           SMALLINT UNSIGNED NULL,       -- NULL = not graded yet
  feedback       TEXT         NULL,
  graded_at      DATETIME     NULL,            -- used for the "graded" notification
  PRIMARY KEY (id),
  UNIQUE KEY uq_submission (assignment_id, student_id),
  KEY idx_submissions_student (student_id, graded_at),
  CONSTRAINT fk_submissions_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
  CONSTRAINT fk_submissions_student    FOREIGN KEY (student_id)    REFERENCES users(id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 13. USER_BADGES
--     Badge rules live in PHP (includes/config.php); the key is stored here.
--     Example keys: first_lesson, quick_learner, quiz_master, course_completer, rising_star
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_badges (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  badge_key  VARCHAR(50)  NOT NULL,
  earned_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_badge (user_id, badge_key),
  CONSTRAINT fk_ub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 14. ANNOUNCEMENTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS announcements (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id   INT UNSIGNED NOT NULL,
  author_id   INT UNSIGNED NOT NULL,
  title       VARCHAR(190) NOT NULL,
  message     TEXT         NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_announcements_course (course_id, created_at),
  KEY idx_announcements_author (author_id),
  CONSTRAINT fk_announce_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_announce_author FOREIGN KEY (author_id) REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SAMPLE DATA
-- Categories are safe to keep.
-- The demo users, course, lessons and quiz are for local testing ONLY:
-- delete them before putting the system on a live server.
-- ============================================================

INSERT IGNORE INTO categories (name) VALUES
  ('Programming'),
  ('Web Development'),
  ('Data and Databases'),
  ('Design'),
  ('Business'),
  ('General');

-- Demo accounts. Password for all three: Password@123
INSERT IGNORE INTO users (id, name, email, password_hash, role, status, email_verified_at) VALUES
  (1, 'Demo Admin',      'admin@lms.test',      '$2y$10$tO6hpM.fSNiahdkO2LpayubVzw7mfezYfvT3lRNzbc98mQAz0yG/O', 'admin',      'active', NOW()),
  (2, 'Demo Instructor', 'instructor@lms.test', '$2y$10$tO6hpM.fSNiahdkO2LpayubVzw7mfezYfvT3lRNzbc98mQAz0yG/O', 'instructor', 'active', NOW()),
  (3, 'Demo Student',    'student@lms.test',    '$2y$10$tO6hpM.fSNiahdkO2LpayubVzw7mfezYfvT3lRNzbc98mQAz0yG/O', 'student',    'active', NOW());

INSERT IGNORE INTO courses (id, instructor_id, category_id, title, description, status) VALUES
  (1, 2, (SELECT id FROM categories WHERE name = 'Web Development'),
   'Introduction to PHP and MySQL',
   'Learn the basics of PHP and MySQL by building a small web application step by step.',
   'published');

INSERT IGNORE INTO lessons (id, course_id, title, type, content, position) VALUES
  (1, 1, 'What is PHP?',        'text', 'PHP is a server-side scripting language used to build dynamic websites.', 1),
  (2, 1, 'Setting up XAMPP',    'text', 'Install XAMPP, start Apache and MySQL, and place your project in htdocs.', 2),
  (3, 1, 'Connecting to MySQL', 'text', 'Use PDO with prepared statements to connect and query the database safely.', 3);

INSERT IGNORE INTO quizzes (id, course_id, title, pass_mark, max_attempts, questions) VALUES
  (1, 1, 'PHP Basics Quiz', 50, 3,
   '[
      {"id":1,"text":"What does PHP run on?","marks":1,
       "options":["The web server","The web browser","The graphics card"],"correct":0},
      {"id":2,"text":"Which approach is recommended for safe database access?","marks":1,
       "options":["PDO with prepared statements","String concatenation in queries"],"correct":0}
    ]');

-- ============================================================
-- END OF SCHEMA
-- ============================================================