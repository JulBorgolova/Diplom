CREATE DATABASE IF NOT EXISTS education_tests
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE education_tests;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('student', 'teacher', 'admin') NOT NULL DEFAULT 'student',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    subject VARCHAR(100) NOT NULL DEFAULT 'Общий предмет',
    time_limit_minutes INT NOT NULL DEFAULT 0,
    visibility_status ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tests_users
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    test_id INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('boolean', 'single', 'multiple', 'sequence', 'matching', 'text') NOT NULL DEFAULT 'single',
    image_path VARCHAR(255) NULL,
    CONSTRAINT fk_questions_tests
        FOREIGN KEY (test_id) REFERENCES tests(id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    answer_text TEXT NULL,
    is_correct BOOLEAN NOT NULL DEFAULT FALSE,
    match_text TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_answers_questions
        FOREIGN KEY (question_id) REFERENCES questions(id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    test_id INT NOT NULL,
    score INT NOT NULL,
    total_questions INT NOT NULL,
    passed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_results_users
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_results_tests
        FOREIGN KEY (test_id) REFERENCES tests(id)
        ON DELETE CASCADE
);