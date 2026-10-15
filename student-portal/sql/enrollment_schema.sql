-- Run ONCE in phpMyAdmin: database css_system -> SQL tab -> paste -> Go
CREATE TABLE IF NOT EXISTS stu_applications (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  first_name     VARCHAR(60)  NOT NULL,
  middle_name    VARCHAR(60)  NULL,
  last_name      VARCHAR(60)  NOT NULL,
  personal_email VARCHAR(150) NOT NULL,
  mobile         VARCHAR(20)  NOT NULL,
  birthdate      DATE NOT NULL,
  course         VARCHAR(120) NOT NULL,
  admission_type VARCHAR(20)  NOT NULL,
  year_level     VARCHAR(30)  NOT NULL,
  last_school    VARCHAR(150) NULL,
  status         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  review_note    VARCHAR(255) NULL,
  reviewed_by    INT NULL,
  reviewed_at    DATETIME NULL,
  student_id     INT NULL,
  submitted_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_status (status),
  KEY idx_email (personal_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE stu_students
  ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN application_id INT NULL;
