# Class Scheduling System (CSS)
Bestlink College of the Philippines — Group 9 BSIT 31001-IM

A web-based, automated class scheduling system built with **PHP + MySQL (PDO) + HTML/CSS/JS**,
covering the core modules from the project charter: Section Assignment, Teacher-Subject Mapping,
real-time Conflict Checking, Room Availability, and Schedule Cloning — with full login/logout
authentication and role-based authorization.

## Features

**Authentication & Security**
- Login / logout with hashed passwords (bcrypt via `password_hash`)
- Session-based auth with session ID regeneration on login (fixation protection)
- Brute-force lockout (5 failed attempts → 15-minute lock)
- CSRF tokens on every form submission
- Full audit trail (logins, logouts, creates/updates/deletes) in `audit_log`

**Authorization (role-based access control)**
- `admin` — full access, manages user accounts and roles, views audit log
- `registrar` — manages schedules, sections, rooms, cloning, conflict resolution
- `program_head` — manages teachers and subjects
- `faculty` — can only view their own personal teaching schedule
- Every page enforces its allowed roles server-side via `requireRole([...])`

**Automation**
- **Real-time Conflict Checker**: AJAX-powered live checking as you fill in the schedule
  form (teacher / room / section double-booking), plus an authoritative server-side check
  before anything is saved (`includes/functions.php::detectScheduleConflicts()`)
- **Room Availability Checker**: instantly find free rooms for a given day/time
- **Schedule Cloning Tool**: copy an entire section's timetable to a new section or term,
  automatically skipping and reporting any entries that would create a conflict

## Setup

1. **Requirements**: PHP 8+, MySQL/MariaDB, a web server (Apache/XAMPP/WAMP/Laragon or `php -S`).
2. Copy this whole folder to your web root (e.g. `htdocs/css-system`).
3. Import the database:
   ```
   mysql -u root -p < sql/schema.sql
   ```
4. Edit `config/db.php` with your MySQL username/password.
5. Open `http://localhost/css-system/reset_admin_password.php` **once** in your browser
   to properly set the admin password hash, then **delete that file**.
6. Go to `http://localhost/css-system/login.php` and log in:
   - Username: `admin`
   - Password: `Admin@123`
7. As admin, create accounts for Registrar, Program Head, and Faculty under **User Accounts**,
   and optionally link a Faculty account to a Teacher profile so they can see "My Schedule".

## Folder structure
```
config/db.php             database connection
sql/schema.sql            full schema + seed data
includes/auth.php         authentication + authorization + CSRF + audit logging
includes/functions.php    shared helpers + scheduling automation logic
includes/header.php, sidebar.php, footer.php   shared layout
assets/css/style.css      styling
assets/js/app.js          live AJAX conflict checker
ajax/check_conflict.php   AJAX endpoint for live conflict checking
login.php / logout.php    authentication
dashboard.php             role-aware landing page
teachers.php / subjects.php / rooms.php / sections.php   CRUD modules
schedules.php             timetable builder with automated conflict detection
clone_schedule.php        schedule cloning tool
my_schedule.php           faculty personal schedule view
users.php                 admin: manage accounts & roles
audit_log.php             admin: security audit trail
```

## Notes
- Change `config/db.php` credentials before deploying.
- Delete `reset_admin_password.php` after first use.
- All SQL queries use PDO prepared statements to prevent SQL injection.
- All output is escaped with `e()` (htmlspecialchars) to prevent XSS.
