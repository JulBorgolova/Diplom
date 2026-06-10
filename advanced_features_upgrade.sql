ALTER TABLE tests
    ADD COLUMN IF NOT EXISTS subject VARCHAR(100) NOT NULL DEFAULT 'Общий предмет',
    ADD COLUMN IF NOT EXISTS time_limit_minutes INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS visibility_status ENUM('draft', 'published') NOT NULL DEFAULT 'draft';

UPDATE tests
SET visibility_status = 'published'
WHERE visibility_status IS NULL OR visibility_status = '';

ALTER TABLE questions
    MODIFY COLUMN question_type ENUM('boolean', 'single', 'multiple', 'sequence', 'matching', 'text') NOT NULL DEFAULT 'single';

UPDATE questions
SET question_type = 'single'
WHERE question_type = 'choice';

ALTER TABLE answers
    MODIFY COLUMN answer_text TEXT NULL,
    ADD COLUMN IF NOT EXISTS match_text TEXT NULL AFTER answer_text,
    ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0 AFTER is_correct;