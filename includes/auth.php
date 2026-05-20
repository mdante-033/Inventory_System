<?php
/**
 * Authentication Functions
 * Handles user login, logout, registration, and password management
 */

require_once __DIR__ . '/session.php';

/**
 * Login user
 */
function login($username, $password, $remember = false) {
    global $pdo;
    
    try {
        // Get user by username
        $stmt = $pdo->prepare("
            SELECT id, username, password, email, full_name, role, is_active 
            FROM users 
            WHERE username = ? AND is_active = true
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return ['success' => false, 'message' => 'Invalid username or password'];
        }
        
        // Verify password
        if (!password_verify($password, $user['password'])) {
            // Log failed attempt
            logActivity($user['id'], 'login_failed', "Failed login attempt for user: $username");
            return ['success' => false, 'message' => 'Invalid username or password'];
        }
        
        createUserSession($user);
        
        // Handle remember me
        if ($remember) {
            $token = bin2hex(random_bytes(32));
            $expiry = time() + (REMEMBER_ME_DAYS * 86400);
            
            // Store remember token (in production, store hashed version)
            setcookie('remember_token', $token, [
                'expires' => $expiry,
                'path' => '/',
                'secure' => inventorySessionIsHttps(),
                'httponly' => true,
                'samesite' => inventorySessionSameSite(),
            ]);
            
            // Store token in database (pseudo-code - add to users table)
            // $stmt = $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
            // $stmt->execute([password_hash($token, PASSWORD_DEFAULT), $user['id']]);
        }
        
        // Update last login
        $stmt = $pdo->prepare("UPDATE users SET updated_at = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);
        
        // Log successful login
        logActivity($user['id'], 'login', "User logged in successfully");
        
        return ['success' => true, 'message' => 'Login successful', 'user' => $user];
        
    } catch (PDOException $e) {
        logError("Login error: " . $e->getMessage());
        return ['success' => false, 'message' => 'An error occurred during login'];
    }
}

/**
 * Logout user
 */
function logout() {
    global $pdo;
    
    // Log logout activity
    if (isset($_SESSION['user_id'])) {
        logActivity($_SESSION['user_id'], 'logout', "User logged out");
        
        // Clear remember token
        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => inventorySessionIsHttps(),
                'httponly' => true,
                'samesite' => inventorySessionSameSite(),
            ]);
        }
    }
    
    destroySession();
    
    return ['success' => true, 'message' => 'Logged out successfully'];
}

/**
 * Register new user
 */
function register($username, $email, $password, $fullName, $role = 'staff') {
    global $pdo;
    
    try {
        // Validate inputs
        if (empty($username) || empty($email) || empty($password) || empty($fullName)) {
            return ['success' => false, 'message' => 'All fields are required'];
        }

        if (strtolower(trim($username)) === strtolower(trim($email))) {
            return ['success' => false, 'message' => 'Username and email must be different'];
        }
        
        // Check if username exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Username already exists'];
        }
        
        // Check if email exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Email already exists'];
        }
        
        // Validate password length
        if (strlen($password) < MIN_PASSWORD_LENGTH) {
            return ['success' => false, 'message' => 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters'];
        }
        
        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        
        // Insert user
        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, full_name, password, role, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
        ");
        $stmt->execute([$username, $email, $fullName, $hashedPassword, $role]);
        
        $userId = $pdo->lastInsertId();
        
        // Log registration
        logActivity($userId, 'register', "New user registered: $username");
        
        return ['success' => true, 'message' => 'Registration successful', 'user_id' => $userId];
        
    } catch (PDOException $e) {
        logError("Registration error: " . $e->getMessage());
        return ['success' => false, 'message' => 'An error occurred during registration'];
    }
}

/**
 * Change password
 */
function changePassword($userId, $currentPassword, $newPassword) {
    global $pdo;
    
    try {
        // Get current password hash
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return ['success' => false, 'message' => 'User not found'];
        }
        
        // Verify current password
        if (!password_verify($currentPassword, $user['password'])) {
            logActivity($userId, 'password_change_failed', "Failed password change attempt");
            return ['success' => false, 'message' => 'Current password is incorrect'];
        }
        
        // Validate new password
        if (strlen($newPassword) < MIN_PASSWORD_LENGTH) {
            return ['success' => false, 'message' => 'New password must be at least ' . MIN_PASSWORD_LENGTH . ' characters'];
        }
        
        // Hash new password
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        
        // Update password
        $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$hashedPassword, $userId]);

        if ((int) ($_SESSION['user_id'] ?? 0) === (int) $userId) {
            session_regenerate_id(true);
            refreshSessionSecurityMetadata();
            rotateSessionCsrfToken();
        }
        
        // Log password change
        logActivity($userId, 'password_changed', "User changed their password");
        
        return ['success' => true, 'message' => 'Password changed successfully'];
        
    } catch (PDOException $e) {
        logError("Password change error: " . $e->getMessage());
        return ['success' => false, 'message' => 'An error occurred while changing password'];
    }
}

/**
 * Reset password (admin function)
 */
function resetPassword($userId, $newPassword) {
    global $pdo;
    
    try {
        // Validate new password
        if (strlen($newPassword) < MIN_PASSWORD_LENGTH) {
            return ['success' => false, 'message' => 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters'];
        }
        
        // Hash new password
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        
        // Update password
        $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$hashedPassword, $userId]);
        
        // Log password reset
        $resetBy = $_SESSION['user_id'] ?? 'system';
        logActivity($userId, 'password_reset', "Password reset by user ID: $resetBy");
        
        return ['success' => true, 'message' => 'Password reset successfully'];
        
    } catch (PDOException $e) {
        logError("Password reset error: " . $e->getMessage());
        return ['success' => false, 'message' => 'An error occurred while resetting password'];
    }
}

/**
 * Check session timeout
 */
function checkSessionTimeout() {
    if (!isset($_SESSION['last_activity'])) {
        return false;
    }
    
    $timeout = SESSION_TIMEOUT; // 30 minutes
    $elapsed = time() - $_SESSION['last_activity'];
    
    if ($elapsed > $timeout) {
        logout();
        return false;
    }
    
    // Update last activity time
    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * Require login
 */
function requireLogin() {
    if (!isLoggedIn()) {
        redirect(APP_URL . '/login.php');
        exit;
    }
    
    // Check session timeout
    if (!checkSessionTimeout()) {
        setFlashMessage('warning', 'Your session has expired. Please login again.');
        redirect(APP_URL . '/login.php');
        exit;
    }
}

/**
 * Require admin role
 */
function requireAdmin() {
    requireLogin();
    
    if (!isAdmin()) {
        setFlashMessage('danger', 'You do not have permission to access this page.');
        redirect(APP_URL . '/dashboard.php');
        exit;
    }
}

/**
 * Require manager or admin role
 */
function requireManager() {
    requireLogin();
    
    if (!isManager()) {
        setFlashMessage('danger', 'You do not have permission to access this page.');
        redirect(APP_URL . '/dashboard.php');
        exit;
    }
}

/**
 * Check permission
 */
function checkPermission($requiredRole) {
    $userRole = getCurrentUserRole();
    
    $roles = [
        'admin' => 3,
        'manager' => 2,
        'staff' => 1,
        'customer' => 0
    ];
    
    $userLevel = $roles[$userRole] ?? 0;
    $requiredLevel = $roles[$requiredRole] ?? 0;
    
    return $userLevel >= $requiredLevel;
}

/**
 * Get user by ID
 */
function getUserById($userId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, username, email, full_name, role, is_active, created_at, updated_at
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch();
        
    } catch (PDOException $e) {
        logError("Get user error: " . $e->getMessage());
        return null;
    }
}

/**
 * Get all users
 */
function getAllUsers($limit = 100, $offset = 0) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, username, email, full_name, role, is_active, created_at, updated_at
            FROM users 
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
        
    } catch (PDOException $e) {
        logError("Get users error: " . $e->getMessage());
        return [];
    }
}

/**
 * Update user
 */
function updateUser($userId, $data) {
    global $pdo;
    
    try {
        $fields = [];
        $values = [];
        
        if (isset($data['username'])) {
            // Check if username is taken by another user
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$data['username'], $userId]);
            if ($stmt->fetch()) {
                return ['success' => false, 'message' => 'Username already exists'];
            }
            $fields[] = 'username = ?';
            $values[] = $data['username'];
        }
        
        if (isset($data['email'])) {
            // Check if email is taken by another user
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$data['email'], $userId]);
            if ($stmt->fetch()) {
                return ['success' => false, 'message' => 'Email already exists'];
            }
            $fields[] = 'email = ?';
            $values[] = $data['email'];
        }
        
        if (isset($data['full_name'])) {
            $fields[] = 'full_name = ?';
            $values[] = $data['full_name'];
        }
        
        if (isset($data['role'])) {
            $fields[] = 'role = ?';
            $values[] = $data['role'];
        }
        
        if (isset($data['is_active'])) {
            $fields[] = 'is_active = ?';
            $values[] = $data['is_active'] ? 1 : 0;
        }
        
        if (empty($fields)) {
            return ['success' => false, 'message' => 'No data to update'];
        }
        
        $fields[] = 'updated_at = NOW()';
        $values[] = $userId;
        
        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        
        logActivity($_SESSION['user_id'] ?? 0, 'user_updated', "User ID $userId updated");
        
        return ['success' => true, 'message' => 'User updated successfully'];
        
    } catch (PDOException $e) {
        logError("Update user error: " . $e->getMessage());
        return ['success' => false, 'message' => 'An error occurred while updating user'];
    }
}

/**
 * Delete user
 */
function deleteUser($userId) {
    global $pdo;
    
    try {
        // Prevent deleting own account
        if ($userId == ($_SESSION['user_id'] ?? 0)) {
            return ['success' => false, 'message' => 'You cannot delete your own account'];
        }
        
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        
        logActivity($_SESSION['user_id'] ?? 0, 'user_deleted', "User ID $userId deleted");
        
        return ['success' => true, 'message' => 'User deleted successfully'];
        
    } catch (PDOException $e) {
        logError("Delete user error: " . $e->getMessage());
        return ['success' => false, 'message' => 'An error occurred while deleting user'];
    }
}
?>
