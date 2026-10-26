-- Quiz builder: extra columns on `quizzes`.
-- Questions (MCQ and essay, with the correct answers) are still stored as JSON
-- in the existing `questions` column, so no other table has to change.
-- Run this once in phpMyAdmin > SQL tab (database: lms_db).

ALTER TABLE `quizzes`
  ADD COLUMN `description`    TEXT              NULL                          AFTER `title`,
  ADD COLUMN `question_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0            AFTER `max_attempts`,
  ADD COLUMN `total_marks`    SMALLINT UNSIGNED NOT NULL DEFAULT 0            AFTER `question_count`,
  ADD COLUMN `source`         ENUM('manual','csv') NOT NULL DEFAULT 'manual'  AFTER `total_marks`;
