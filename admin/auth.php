<?php
// admin/auth.php

require_once __DIR__ . '/../includes/session.php';

class AdminAuth
{
    private $pdo;
    private const ALLOWED_ROLES = ['admin', 'manager'];

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function login($username, $password)
    {
        try {
            if (!$this->pdo instanceof PDO) {
                return ['success' => false, 'message' => 'Login failed'];
            }

            $stmt = $this->pdo->prepare("
                SELECT id, username, password, email, full_name, role, is_active, account_status
                FROM users
                WHERE (LOWER(username) = LOWER(:login) OR LOWER(email) = LOWER(:login))
                  AND role IN ('admin', 'manager')
                LIMIT 1
            ");
            $stmt->execute(['login' => $username]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($admin
                && $this->isAllowedRole($admin['role'] ?? '')
                && $this->isTruthy($admin['is_active'] ?? false)
                && ($admin['account_status'] ?? 'active') !== 'suspended'
                && password_verify($password, $admin['password'])) {
                $this->storeAdminSession($admin);

                $stmt = $this->pdo->prepare("UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$admin['id']]);

                return ['success' => true];
            }

            return ['success' => false, 'message' => 'Invalid credentials'];
        } catch (PDOException $e) {
            error_log("Admin login error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Login failed'];
        }
    }

    public function isLoggedIn()
    {
        $role = $_SESSION['admin_role'] ?? $_SESSION['role'] ?? '';

        return (!empty($_SESSION['admin_logged_in']) || !empty($_SESSION['logged_in']))
            && $this->isAllowedRole($role);
    }

    public function logout()
    {
        destroySession();
        return true;
    }

    public function requireLogin()
    {
        if (!$this->isLoggedIn()) {
            $this->deny(401, 'Admin session expired. Please log in again.');
        }

        if (!$this->verifyActiveAdmin()) {
            destroySession(false);
            $this->deny(403, 'Access denied.');
        }

        $this->ensureAdminCsrfToken();
    }

    public function getCsrfToken()
    {
        return $this->ensureAdminCsrfToken();
    }

    public function validateCsrfToken($token)
    {
        return isset($_SESSION['admin_csrf_token'])
            && is_string($token)
            && hash_equals((string) $_SESSION['admin_csrf_token'], $token);
    }

    private function storeAdminSession(array $admin)
    {
        session_regenerate_id(true);
        $_SESSION = [];

        $_SESSION['user_id'] = (int) $admin['id'];
        $_SESSION['username'] = (string) $admin['username'];
        $_SESSION['email'] = (string) ($admin['email'] ?? '');
        $_SESSION['full_name'] = (string) ($admin['full_name'] ?? $admin['username']);
        $_SESSION['role'] = (string) $admin['role'];
        $_SESSION['logged_in'] = true;

        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_username'] = (string) $admin['username'];
        $_SESSION['admin_role'] = (string) $admin['role'];
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['login_time'] = time();

        refreshSessionSecurityMetadata();
        $this->ensureAdminCsrfToken(true);
    }

    private function verifyActiveAdmin()
    {
        $adminId = (int) ($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);
        if ($adminId <= 0) {
            return false;
        }

        try {
            if (!$this->pdo instanceof PDO) {
                return false;
            }

            $stmt = $this->pdo->prepare("
                SELECT id, username, email, full_name, role, is_active, account_status
                FROM users
                WHERE id = ?
                  AND role IN ('admin', 'manager')
                LIMIT 1
            ");
            $stmt->execute([$adminId]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$admin
                || !$this->isAllowedRole($admin['role'] ?? '')
                || !$this->isTruthy($admin['is_active'] ?? false)
                || ($admin['account_status'] ?? 'active') === 'suspended') {
                return false;
            }

            $_SESSION['user_id'] = (int) $admin['id'];
            $_SESSION['username'] = (string) $admin['username'];
            $_SESSION['email'] = (string) ($admin['email'] ?? '');
            $_SESSION['full_name'] = (string) ($admin['full_name'] ?? $admin['username']);
            $_SESSION['role'] = (string) $admin['role'];
            $_SESSION['logged_in'] = true;
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_username'] = (string) $admin['username'];
            $_SESSION['admin_role'] = (string) $admin['role'];
            $_SESSION['admin_logged_in'] = true;

            return true;
        } catch (PDOException $e) {
            error_log("Admin session verification error: " . $e->getMessage());
            return false;
        }
    }

    private function ensureAdminCsrfToken($rotate = false)
    {
        if ($rotate || empty($_SESSION['admin_csrf_token']) || !is_string($_SESSION['admin_csrf_token'])) {
            $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['admin_csrf_token'];
    }

    private function isAllowedRole($role)
    {
        return in_array((string) $role, self::ALLOWED_ROLES, true);
    }

    private function isTruthy($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 't', 'yes', 'y'], true);
    }

    private function deny($statusCode, $message)
    {
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        $acceptsJson = stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

        if ($isAjax || $acceptsJson) {
            http_response_code($statusCode);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => $message,
                'redirect' => '/login.php',
            ]);
            exit();
        }

        if ($statusCode === 403) {
            http_response_code(403);
            echo 'Access denied';
            exit();
        }

        header('Location: /login.php');
        exit();
    }
}

require_once __DIR__ . '/../config/database.php';
$adminAuth = new AdminAuth($pdo);
