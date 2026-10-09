-- Run once in phpMyAdmin (same database as the Class Scheduling System / Teacher Registration)
CREATE TABLE IF NOT EXISTS calendar_events (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(150) NOT NULL,
  description TEXT NULL,
  event_type  ENUM('holiday','event','exam','suspension','other') NOT NULL DEFAULT 'event',
  start_date  DATE NOT NULL,
  end_date    DATE NOT NULL,
  start_time  TIME NULL,
  end_time    TIME NULL,
  no_classes  TINYINT(1) NOT NULL DEFAULT 0,
  created_by  INT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
