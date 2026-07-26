-- Multi-criteria event evaluation: replaces the single 1-5 "rating" with 15
-- Likert-scored criteria (see sked_evaluation_criteria() in includes/events.php)
-- plus one open-ended question. event_evaluations stays a one-row-per-
-- (event,user) header; its `rating` becomes that user's own average across
-- their 15 answers, so every existing AVG(rating) consumer keeps working
-- unchanged. `comments` becomes the single open-ended answer.

ALTER TABLE event_evaluations
    MODIFY COLUMN rating DECIMAL(3,2) UNSIGNED NOT NULL,
    MODIFY COLUMN comments TEXT NULL;

CREATE TABLE IF NOT EXISTS event_evaluation_answers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    evaluation_id INT UNSIGNED NOT NULL,
    question_key VARCHAR(40) NOT NULL,
    answer_value TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_eval_answer (evaluation_id, question_key),
    KEY idx_eval_answer_eval (evaluation_id),
    CONSTRAINT fk_eval_answer_eval FOREIGN KEY (evaluation_id)
        REFERENCES event_evaluations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
