ALTER TABLE lessons
    ADD COLUMN module_id INT UNSIGNED NULL AFTER course_id,
    ADD KEY idx_lessons_module_position (module_id, position),
    ADD CONSTRAINT fk_lessons_module
        FOREIGN KEY (module_id) REFERENCES modules (id)
        ON DELETE SET NULL;
