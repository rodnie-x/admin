<?php
// >>> PLACE AT: css-system/users.php  (replace the old file)  [batch 8: no inline handlers]
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireRole(['admin']);

$pdo = getDB();
$roles = $pdo->query("SELECT * FROM roles ORDER BY id")->fetchAll();
$teachers = $pdo->query("SELECT * FROM teachers ORDER BY full_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 0);
        $password = $_POST['password'] ?? '';
        $linkTeacher = (int)($_POST['link_teacher_id'] ?? 0);

        if ($username === '' || $email === '' || $fullName === '' || strlen($password) < 8) {
            flash('error', 'All fields are required and password must be at least 8 characters.');
        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, role_id) VALUES (?,?,?,?,?)");
                $stmt->execute([$username, $email, $hash, $fullName, $roleId]);
                $newUserId = $pdo->lastInsertId();

                if ($linkTeacher > 0) {
                    $pdo->prepare("UPDATE teachers SET user_id=? WHERE id=?")->execute([$newUserId, $linkTeacher]);
                }

                logAudit($_SESSION['user_id'], 'CREATE', 'users', $newUserId, "Created user account $username");
                flash('success', "User '$username' created.");
            } catch (PDOException $e) {
                flash('error', 'Username or email already exists.');
            }
        }
    } elseif ($action === 'toggle_status') {
        $id = (int)$_POST['id'];
        if ($id === (int)$_SESSION['user_id']) {
            flash('error', 'You cannot deactivate your own account.');
            redirect('users.php');
        }
        $stmt = $pdo->prepare("SELECT status FROM users WHERE id=?");
        $stmt->execute([$id]);
        $cur = $stmt->fetchColumn();
        $new = $cur === 'active' ? 'inactive' : 'active';
        $pdo->prepare("UPDATE users SET status=? WHERE id=?")->execute([$new, $id]);
        logAudit($_SESSION['user_id'], 'UPDATE', 'users', $id, "Status changed to $new");
        flash('success', 'User status updated.');
    } elseif ($action === 'reset_password') {
        $id = (int)$_POST['id'];
        $newPass = $_POST['new_password'] ?? '';
        if (strlen($newPass) < 8) {
            flash('error', 'New password must be at least 8 characters.');
        } else {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash=?, failed_attempts=0, locked_until=NULL WHERE id=?")->execute([$hash, $id]);
            logAudit($_SESSION['user_id'], 'UPDATE', 'users', $id, 'Password reset by admin');
            flash('success', 'Password reset successfully.');
        }
    } elseif ($action === 'change_role') {
        $id = (int)$_POST['id'];
        $roleId = (int)$_POST['role_id'];
        if ($id === (int)$_SESSION['user_id']) {
            flash('error', 'You cannot change your own role.');
            redirect('users.php');
        }
        if (!in_array($roleId, array_map('intval', array_column($roles, 'id')), true)) {
            flash('error', 'That role does not exist.');
            redirect('users.php');
        }
        $pdo->prepare("UPDATE users SET role_id=? WHERE id=?")->execute([$roleId, $id]);
        logAudit($_SESSION['user_id'], 'UPDATE', 'users', $id, "Role changed to role_id=$roleId");
        flash('success', 'Role updated.');
    }
    redirect('users.php');
}

$users = $pdo->query("SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.username")->fetchAll();

$pageTitle = 'User Accounts';
include __DIR__ . '/includes/header.php';
?>
<h1>🔐 User Accounts</h1>
<p>Manage login accounts, roles (authorization), and password resets.</p>

<div class="card">
    <h3>Create New Account</h3>
    <form method="POST" action="users.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div><label>Username</label><input type="text" name="username" required></div>
            <div><label>Email</label><input type="email" name="email" required></div>
            <div><label>Full Name</label><input type="text" name="full_name" required></div>
            <div><label>Password</label><input type="password" name="password" required minlength="8" placeholder="min. 8 characters"></div>
            <div>
                <label>Role</label>
                <select name="role_id" required>
                    <?php foreach ($roles as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Link to Teacher Profile (optional, for Faculty role)</label>
                <select name="link_teacher_id">
                    <option value="0">— none —</option>
                    <?php foreach ($teachers as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['full_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>
        <br>
        <button class="btn btn-gold" type="submit">Create Account</button>
    </form>
</div>

<div class="card">
    <h3>All Accounts</h3>
    <table>
        <tr><th>Username</th><th>Full Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last Login</th><th>Actions</th></tr>
        <?php foreach ($users as $u): ?>
        <tr>
            <td><?= e($u['username']) ?></td>
            <td><?= e($u['full_name']) ?></td>
            <td><?= e($u['email']) ?></td>
            <td>
                <form class="inline" method="POST" action="users.php">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="change_role">
                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <select name="role_id" data-autosubmit>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= $r['id'] ?>" <?= $r['id']==$u['role_id']?'selected':'' ?>><?= e($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </td>
            <td><span class="badge badge-<?= $u['status'] ?>"><?= e($u['status']) ?></span></td>
            <td><?= $u['last_login'] ? e($u['last_login']) : '—' ?></td>
            <td>
                <form class="inline" method="POST" action="users.php" data-confirm="Toggle status for this account?">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <button class="btn btn-sm btn-secondary" type="submit"><?= $u['status']==='active'?'Deactivate':'Activate' ?></button>
                </form>
                <form class="inline" method="POST" action="users.php" data-prompt-password>
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <input type="hidden" name="new_password" class="np-field">
                    <button class="btn btn-sm" type="submit">Reset Password</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>