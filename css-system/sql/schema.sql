-- Class Scheduling System (CSS)
-- Bestlink College of the Philippines — Group 9 BSIT 31001-IM
-- Full schema + seed data

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS schedules;
DROP TABLE IF EXISTS sections;
DROP TABLE IF EXISTS rooms;
DROP TABLE IF EXISTS subjects;
DROP TABLE IF EXISTS teachers;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------
-- roles
-- ---------------------------------------------------------------
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO roles (id, name) VALUES
    (1, 'admin'),
    (2, 'registrar'),
    (3, 'program_head'),
    (4, 'faculty');

-- ---------------------------------------------------------------
-- users
-- ---------------------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    role_id INT NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    failed_attempts INT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL DEFAULT NULL,
    last_login DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Placeholder hash; run reset_admin_password.php once to set the real one (Admin@123)
INSERT INTO users (username, email, password_hash, full_name, role_id, status) VALUES
    ('admin', 'admin@bcp.edu.ph', '$2y$10$abcdefghijklmnopqrstuuvwxyzABCDEFGHIJKLMNOPQRSTUVWX1', 'System Administrator', 1, 'active');

-- ---------------------------------------------------------------
-- teachers
-- ---------------------------------------------------------------
CREATE TABLE teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    department VARCHAR(100) DEFAULT NULL,
    max_units INT NOT NULL DEFAULT 24,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    user_id INT NULL DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO teachers (full_name, department, max_units, status) VALUES
    ('Juan Dela Cruz', 'College of Computer Studies', 24, 'active'),
    ('Maria Santos', 'College of Computer Studies', 24, 'active'),
    ('Pedro Reyes', 'College of Engineering', 21, 'active');

-- ---------------------------------------------------------------
-- subjects
-- ---------------------------------------------------------------
CREATE TABLE subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    title VARCHAR(150) NOT NULL,
    units DECIMAL(3,1) NOT NULL DEFAULT 3.0,
    lecture_hours DECIMAL(3,1) NOT NULL DEFAULT 3.0,
    lab_hours DECIMAL(3,1) NOT NULL DEFAULT 0.0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO subjects (code, title, units, lecture_hours, lab_hours) VALUES
    ('IT301', 'Systems Integration and Architecture', 3, 2, 3),
    ('IT302', 'Information Assurance and Security', 3, 3, 0),
    ('CS201', 'Data Structures and Algorithms', 3, 2, 3);

-- ---------------------------------------------------------------
-- rooms
-- ---------------------------------------------------------------
CREATE TABLE rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    type ENUM('lecture','laboratory','other') NOT NULL DEFAULT 'lecture',
    capacity INT NOT NULL DEFAULT 40,
    status ENUM('available','under_maintenance') NOT NULL DEFAULT 'available'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO rooms (name, type, capacity, status) VALUES
    ('Room 301', 'lecture', 45, 'available'),
    ('Computer Lab 1', 'laboratory', 35, 'available'),
    ('Room 205', 'lecture', 40, 'available');

-- ---------------------------------------------------------------
-- sections
-- ---------------------------------------------------------------
CREATE TABLE sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    program VARCHAR(50) DEFAULT NULL,
    year_level VARCHAR(30) DEFAULT NULL,
    semester ENUM('1st','2nd','summer') NOT NULL DEFAULT '1st',
    school_year VARCHAR(20) NOT NULL,
    student_count INT NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_section_term (name, semester, school_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO sections (name, program, year_level, semester, school_year, student_count) VALUES
    ('BSIT 3-1', 'BSIT', '3rd Year', '1st', '2026-2027', 40);

-- ---------------------------------------------------------------
-- schedules
-- ---------------------------------------------------------------
CREATE TABLE schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT NOT NULL,
    subject_id INT NOT NULL,
    teacher_id INT NOT NULL,
    room_id INT NOT NULL,
    day_of_week ENUM('Mon','Tue','Wed','Thu','Fri','Sat') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    semester ENUM('1st','2nd','summer') NOT NULL,
    school_year VARCHAR(20) NOT NULL,
    status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    created_by INT NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id),
    FOREIGN KEY (teacher_id) REFERENCES teachers(id),
    FOREIGN KEY (room_id) REFERENCES rooms(id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_teacher_day (teacher_id, day_of_week, semester, school_year),
    INDEX idx_room_day (room_id, day_of_week, semester, school_year),
    INDEX idx_section_day (section_id, day_of_week, semester, school_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- audit_log
-- ---------------------------------------------------------------
CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL DEFAULT NULL,
    action VARCHAR(20) NOT NULL,
    table_name VARCHAR(50) DEFAULT NULL,
    record_id INT DEFAULT NULL,
    details VARCHAR(255) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
