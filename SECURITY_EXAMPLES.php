<?php
/**
 * QUICK START GUIDE - Security Features Integration
 * Copy this to your admin.php or any page that needs security
 */

// ============================================================================
// 1. INCLUDE ALL SECURITY MODULES AT TOP OF YOUR PAGE
// ============================================================================

require_once __DIR__ . '/includes/session.php';                // Session + security headers
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/validators.php';             // Input validation
require_once __DIR__ . '/includes/output.php';                 // Output escaping
require_once __DIR__ . '/includes/encryption.php';             // Encryption/hashing
require_once __DIR__ . '/includes/rate_limiter.php';           // Rate limiting
require_once __DIR__ . '/includes/security_logger.php';        // Security logging

/** @var PDO|null $pdo */
$pdo = getDBConnection();
if (!$pdo instanceof PDO) {
    http_response_code(503);
    error_log($db_connection_error ?? 'Database connection unavailable.');
    exit('Database connection unavailable. Please try again later.');
}

// ============================================================================
// 2. INITIALIZE SECURITY LOGGER
// ============================================================================

$securityLogger = null;
try {
    $securityLogger = new SecurityLogger($pdo);
} catch (\Exception $e) {
    error_log('SecurityLogger initialization error: ' . $e->getMessage());
}

// ============================================================================
// 3. CHECK AUTHENTICATION & PERMISSIONS
// ============================================================================

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// Optionally check for admin role
if (!isAdmin()) {
    if ($securityLogger) {
        $securityLogger->logPermissionDenied(
            (int)$_SESSION['user_id'],
            'access_admin_page',
            $_SERVER['REQUEST_URI']
        );
    }
    die('Access denied');
}

// ============================================================================
// 4. HANDLE FORM SUBMISSIONS WITH VALIDATION
// ============================================================================

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Security token expired. Please try again.';
        if ($securityLogger) {
            $securityLogger->logSuspiciousActivity('csrf_validation_failed');
        }
    }
    // Your form processing here
    elseif (isset($_POST['action']) && $_POST['action'] === 'update_product') {
        // Validate inputs
        $productName = InputValidator::sanitizeString($_POST['name'] ?? '', 100);
        $productPrice = InputValidator::validateInt($_POST['price'] ?? 0, 1);
        $productDescription = InputValidator::sanitizeString($_POST['description'] ?? '', 1000);

        if (empty($productName)) {
            $errors[] = 'Product name is required';
        }

        if ($productPrice === null || $productPrice < 1) {
            $errors[] = 'Price must be a positive number';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare('
                    UPDATE products
                    SET name = ?, price = ?, description = ?, updated_at = NOW()
                    WHERE id = ?
                ');

                $stmt->execute([
                    $productName,
                    $productPrice,
                    $productDescription,
                    InputValidator::validatePositiveInt($_POST['id'] ?? 0)
                ]);

                $success = true;

                if ($securityLogger) {
                    $securityLogger->info('Product updated', [
                        'user_id' => (int)$_SESSION['user_id'],
                        'product_id' => $_POST['id'],
                        'changes' => ['name', 'price', 'description']
                    ]);
                }
            } catch (\PDOException $e) {
                $errors[] = 'Database error occurred';
                if ($securityLogger) {
                    $securityLogger->logSqlError($e);
                }
            }
        }
    }
}

// Generate CSRF token for forms
$csrfToken = generateCSRFToken();

// ============================================================================
// 5. HANDLE FILE UPLOADS SECURELY
// ============================================================================

if (isset($_FILES['avatar'])) {
    $uploadErrors = InputValidator::validateFileUpload(
        $_FILES['avatar'],
        ['image/jpeg', 'image/png', 'image/webp'],
        2097152 // 2MB
    );

    if (!empty($uploadErrors)) {
        $errors = array_merge($errors, $uploadErrors);
    } else {
        // Generate safe filename
        $originalName = basename($_FILES['avatar']['name']);
        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
        $safeFilename = EncryptionHandler::generateRandomString(32) . '.' . strtolower($ext);
        $uploadPath = __DIR__ . '/uploads/' . $safeFilename;

        if (move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadPath)) {
            chmod($uploadPath, 0644);

            if ($securityLogger) {
                $securityLogger->logFileUpload(
                    (int)$_SESSION['user_id'],
                    $safeFilename,
                    $_FILES['avatar']['type'],
                    $_FILES['avatar']['size']
                );
            }

            // Update user avatar in database
            try {
                $stmt = $pdo->prepare('UPDATE users SET avatar = ? WHERE id = ?');
                $stmt->execute([$safeFilename, $_SESSION['user_id']]);
            } catch (\PDOException $e) {
                if ($securityLogger) {
                    $securityLogger->logSqlError($e);
                }
            }
        } else {
            $errors[] = 'Failed to upload file';
        }
    }
}

// ============================================================================
// 6. DISPLAY ERRORS AND SUCCESS MESSAGES SAFELY
// ============================================================================

if (!empty($errors)):
    ?>
    <div class="alert alert-danger">
        <strong>Errors:</strong>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?php echo OutputEscaper::e($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
endif;

if ($success):
    ?>
    <div class="alert alert-success">
        <strong>Success!</strong> Your changes have been saved.
    </div>
    <?php
endif;
?>

<!-- ====================================================================== -->
<!-- 7. FORM WITH CSRF PROTECTION AND SAFE OUTPUT -->
<!-- ====================================================================== -->

<form method="POST" enctype="multipart/form-data">
    <!-- CSRF Token -->
    <input type="hidden" name="csrf_token" value="<?php echo OutputEscaper::escapeAttr($csrfToken); ?>">
    <input type="hidden" name="action" value="update_product">

    <!-- Example: Safe display of user data -->
    <div class="form-group">
        <label>Current User:</label>
        <p><?php echo OutputEscaper::e($_SESSION['username'] ?? 'Unknown'); ?></p>
    </div>

    <!-- Example: Input field -->
    <div class="form-group">
        <label for="name">Product Name:</label>
        <input
            type="text"
            id="name"
            name="name"
            required
            value="<?php echo OutputEscaper::escapeAttr($_POST['name'] ?? ''); ?>"
            maxlength="100"
        >
    </div>

    <!-- Example: File upload -->
    <div class="form-group">
        <label for="avatar">Upload Avatar (JPEG/PNG, max 2MB):</label>
        <input
            type="file"
            id="avatar"
            name="avatar"
            accept="image/jpeg,image/png,image/webp"
        >
    </div>

    <!-- Example: Submit button -->
    <button type="submit">Update</button>
</form>

<!-- ====================================================================== -->
<!-- 8. API ENDPOINT WITH RATE LIMITING AND LOGGING -->
<!-- ====================================================================== -->

<?php
// Example API endpoint
if (strpos($_SERVER['REQUEST_URI'], '/api/products') !== false) {
    header('Content-Type: application/json');

    // Check rate limiting
    $limiter = new RateLimiter($pdo);
    if (!$limiter->checkApiLimit($_SESSION['api_key'] ?? 'none', 60)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many requests']);
        exit;
    }

    // Record the request
    $limiter->recordApiRequest($_SESSION['api_key'] ?? 'none', $_SERVER['REQUEST_URI']);

    // Log the API call
    if ($securityLogger) {
        $securityLogger->logApiRequest(
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['REQUEST_URI'],
            200
        );
    }

    // Validate input
    $productId = InputValidator::validatePositiveInt($_GET['id'] ?? 0);
    if ($productId === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid product ID']);
        exit;
    }

    // Fetch product
    try {
        $stmt = $pdo->prepare('SELECT id, name, price FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $product = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$product) {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found']);
            exit;
        }

        // Return safe JSON response
        http_response_code(200);
        echo OutputEscaper::encodeJson($product);
    } catch (\PDOException $e) {
        if ($securityLogger) {
            $securityLogger->logSqlError($e);
        }
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
}
?>

<!-- ====================================================================== -->
<!-- 9. EXAMPLES OF COMMON SECURITY PATTERNS -->
<!-- ====================================================================== -->

<?php
/*
 * EMAIL VALIDATION
 */
$email = $_POST['email'] ?? '';
if (!InputValidator::validateEmail($email)) {
    echo "Invalid email";
} else {
    $safeEmail = InputValidator::sanitizeEmail($email);
    // Use $safeEmail in database query
}

/*
 * PASSWORD STRENGTH CHECK
 */
$password = $_POST['password'] ?? '';
$passwordErrors = InputValidator::validatePassword($password);
if (!empty($passwordErrors)) {
    foreach ($passwordErrors as $error) {
        echo OutputEscaper::e($error);
    }
} else {
    $passwordHash = EncryptionHandler::hashPassword($password);
    // Store $passwordHash in database
}

/*
 * PHONE NUMBER SANITIZATION
 */
$phone = $_POST['phone'] ?? '';
if (InputValidator::validatePhone($phone)) {
    $safePhone = InputValidator::sanitizePhone($phone);
    // $safePhone is now in E.164 format
}

/*
 * PERMISSION CHECK WITH LOGGING
 */
$resourceId = InputValidator::validatePositiveInt($_GET['id'] ?? 0);
$userRole = $_SESSION['role'] ?? 'guest';

if ($userRole !== 'admin' && $userRole !== 'manager') {
    if ($securityLogger) {
        $securityLogger->logPermissionDenied(
            (int)$_SESSION['user_id'],
            'edit_resource',
            'resource_' . $resourceId
        );
    }
    die('Access denied');
}

/*
 * SUSPICIOUS ACTIVITY DETECTION
 */
$inputLength = strlen($_POST['search'] ?? '');
if ($inputLength > 1000) {
    if ($securityLogger) {
        $securityLogger->logSuspiciousActivity('suspicious_input_length', [
            'length' => $inputLength,
            'threshold' => 1000
        ]);
    }
}

/*
 * RATE LIMITING FOR CUSTOM ACTION
 */
$limiter = new RateLimiter($pdo, 'password_reset_' . $_SESSION['user_id']);
if ($limiter->isLimited(3, 60)) { // 3 attempts per 60 minutes
    echo "Too many password reset attempts. Try again later.";
} else {
    $limiter->recordAttempt(false);
    // Process password reset
}
?>
