<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/logger.php';

if (isset($_SESSION['user_id']) && !empty($_SESSION['logged_in'])) {
    header('Location: shop.php');
    exit();
}

$error = '';
$success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);
$csrfToken = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    $postedCsrfToken = $_POST['csrf_token'] ?? '';

    Logger::info('Customer login attempt', ['login' => $login, 'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);

    if (!validateCSRFToken((string) $postedCsrfToken)) {
        $error = 'Your session expired. Refresh the page and try again.';
        Logger::warn('Customer login blocked by invalid CSRF token', ['login' => $login, 'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    } elseif ($login === '' || $password === '') {
        $error = 'Please enter both your email or username and password.';
        Logger::warn('Customer login validation failed - missing fields', ['login' => $login]);
    } elseif (!$pdo instanceof PDO) {
        $error = $db_connection_error ?: 'Database connection is unavailable. Please try again later.';
        Logger::error('Customer login - DB unavailable', ['error' => $db_connection_error ?? 'unknown']);
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT id, username, password, email, full_name, role
                FROM users
                WHERE (LOWER(username) = LOWER(:login) OR LOWER(email) = LOWER(:email))
                  AND is_active = TRUE
                LIMIT 1
            ");
            $stmt->execute([
                'login' => $login,
                'email' => $login,
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                createUserSession($user);

                $updateStmt = $pdo->prepare('UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $updateStmt->execute(['id' => $user['id']]);

                Logger::info('Customer login successful', ['login' => $login, 'user_id' => $user['id']]);

                header('Location: shop.php');
                exit();
            }

            $error = 'Invalid email, username, or password.';
            Logger::warn('Customer invalid credentials', ['login' => $login, 'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
        } catch (PDOException $e) {
            Logger::error('User login PDOException', ['message' => $e->getMessage()]);
            $error = 'Unable to sign you in right now. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container">
        <div class="card mt-5 mx-auto" style="max-width: 520px;">
            <div class="card-body">
                <h4 class="mb-3">Customer Login</h4>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="mb-3">
                        <label class="form-label" for="login">Username or Email</label>
                        <input
                            type="text"
                            id="login"
                            name="login"
                            class="form-control"
                            value="<?= htmlspecialchars($_POST['login'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <input type="password" id="password" name="password" class="form-control" required>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">Login</button>
                </form>

                <div class="mt-3">
                    No account? <a href="register.php">Register</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
