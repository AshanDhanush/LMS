-- Cohorts: named groups of students inside one course (e.g. "Batch Jan 2026").
-- Run in phpMyAdmin > SQL tab (database: lms_db). Steps 1-3 are safe to repeat.
-- Step 4 adds a foreign key and must be run only once.

-- 1. The cohorts of a course
CREATE TABLE IF NOT EXISTS `cohorts` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `course_id`   INT UNSIGNED NOT NULL,
  `name`        VARCHAR(120) NOT NULL,
  `description` TEXT NULL,
  `start_date`  DATE NULL,
  `end_date`    DATE NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cohort_name` (`course_id`, `name`),
  CONSTRAINT `fk_cohort_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Which students belong to which cohort
CREATE TABLE IF NOT EXISTS `cohort_members` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cohort_id` INT UNSIGNED NOT NULL,
  `user_id`   INT UNSIGNED NOT NULL,
  `added_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cohort_member` (`cohort_id`, `user_id`),
  KEY `idx_cohort_members_user` (`user_id`),
  CONSTRAINT `fk_cm_cohort` FOREIGN KEY (`cohort_id`) REFERENCES `cohorts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cm_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. An announcement can now be for one cohort (NULL = the whole course)
ALTER TABLE `announcements`
  ADD COLUMN IF NOT EXISTS `cohort_id` INT UNSIGNED NULL AFTER `course_id`,
  ADD KEY IF NOT EXISTS `idx_announcements_cohort` (`cohort_id`);

-- 4. RUN ONCE: delete a cohort and its announcements go with it
ALTER TABLE `announcements`
  ADD CONSTRAINT `fk_announce_cohort` FOREIGN KEY (`cohort_id`) REFERENCES `cohorts` (`id`) ON DELETE CASCADE;
