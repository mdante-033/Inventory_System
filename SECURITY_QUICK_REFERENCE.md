# 🔐 Security Quick Reference Card

## For Developers - Copy & Paste Ready

### Top of Every Page
```php
<?php
require_once __DIR__ . '/includes/session.php';      // ← Includes security headers
require_once __DIR__ . '/includes/validators.php';
require_once __DIR__ . '/includes/output.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/security_logger.php';

$logger = new SecurityLogger($pdo);
```

---

## Input Validation Patterns

### Email
```php
$email = InputValidator::sanitizeEmail($_POST['email'] ?? '');
if (!InputValidator::validateEmail($email)) {
    $logger->warning('Invalid email');
}
```

### Password
```php
$errors = InputValidator::validatePassword($_POST['password'] ?? '');
if (!empty($errors)) {
    $logger->warning('Weak password: ' . implode(', ', $errors));
} else {
    $hash = EncryptionHandler::hashPassword($_POST['password']);
}
```

### Integer
```php
$id = InputValidator::validatePositiveInt($_GET['id'] ?? 0);
if (!$id) {
    die('Invalid ID');
}
// Use in query: WHERE id = ?
```

### String
```php
$name = InputValidator::sanitizeString($_POST['name'] ?? '', 100);
// Now safe for database and output
```

### File Upload
```php
$errors = InputValidator::validateFileUpload(
    $_FILES['image'],
    ['image/jpeg', 'image/png'],
    5242880
);
if (!empty($errors)) {
    // Show errors
} else {
    // Upload OK
}
```

### Rate Limiting
```php
$limiter = new RateLimiter($pdo, $username);
if ($limiter->isLimited(5, 15)) {
    $logger->warning('Rate limit exceeded');
    die('Too many attempts');
}
$limiter->recordAttempt(false);
```

---

## Output Escaping Patterns

### HTML
```php
<!-- Safe: -->
<?php echo OutputEscaper::e($userInput); ?>

<!-- Also safe: -->
<?php echo OutputEscaper::escapeHtml($userInput); ?>
```

### HTML Attributes
```html
<img alt="<?php echo OutputEscaper::escapeAttr($alt); ?>">
<input value="<?php echo OutputEscaper::escapeAttr($value); ?>">
```

### JavaScript
```html
<script>
const data = <?php echo OutputEscaper::escapeJs($phpArray); ?>;
</script>
```

### JSON API Response
```php
header('Content-Type: application/json');
echo OutputEscaper::encodeJson($data);
```

### URLs (href)
```html
<a href="<?php echo OutputEscaper::safeUrl($url); ?>">Link</a>
```

---

## Security Patterns

### Protected Page
```php
<?php
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}
if (!isAdmin()) {
    $logger->logPermissionDenied($_SESSION['user_id'], 'access', 'admin');
    die('Access denied');
}
```

### CSRF Form
```html
<form method="POST">
    <input type="hidden" name="csrf_token" 
           value="<?php echo OutputEscaper::escapeAttr($csrfToken); ?>">
    <!-- rest of form -->
</form>
```

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Security error');
    }
    // Process form
}
```

### Safe Database Query
```php
// ✅ CORRECT - Prepared statement
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);

// ❌ WRONG - Never do this!
// $query = "SELECT * FROM users WHERE id = $id";
```

### Logging Success/Failure
```php
if ($success) {
    $logger->info('User logged in', ['user_id' => $user['id']]);
} else {
    $logger->warning('Failed login', ['username' => InputValidator::maskSensitive($username)]);
}
```

---

## Common Mistakes to Avoid

```php
❌ echo $_POST['name'];                    // XSS vulnerability
✅ echo OutputEscaper::e($_POST['name']);

❌ $query = "WHERE email = '$email'";      // SQL injection
✅ $stmt->execute(['email' => $email]);

❌ $pwd = $_POST['password'];              // Plaintext password
✅ $hash = EncryptionHandler::hashPassword($_POST['password']);

❌ if ($pwd == $hash) {                    // Timing attack
✅ if (EncryptionHandler::verifyPassword($pwd, $hash)) {

❌ $_SESSION['user_id'] = $_GET['uid'];    // No validation
✅ $_SESSION['user_id'] = InputValidator::validatePositiveInt($_GET['uid']);
```

---

## Database Operations

### Safe Insert
```php
$stmt = $pdo->prepare('
    INSERT INTO products (name, price, created_at)
    VALUES (?, ?, NOW())
');
$stmt->execute([
    InputValidator::sanitizeString($name),
    InputValidator::validateInt($price, 1)
]);
```

### Safe Update
```php
$stmt = $pdo->prepare('
    UPDATE users SET email = ? WHERE id = ?
');
$stmt->execute([
    InputValidator::sanitizeEmail($email),
    InputValidator::validatePositiveInt($userId)
]);
```

### Safe Select
```php
$stmt = $pdo->prepare('
    SELECT * FROM users WHERE username = ? LIMIT 1
');
$stmt->execute([InputValidator::sanitizeString($username)]);
$user = $stmt->fetch();
```

---

## Error Handling

### ✅ Do This
```php
try {
    // Database operation
    $stmt->execute($data);
} catch (\PDOException $e) {
    // Log the error
    $logger->logSqlError($e);
    // Show safe message to user
    echo "Database error occurred";
    // Don't show stack trace!
}
```

### ❌ Don't Do This
```php
// ❌ Shows stack trace and SQL to users
try {
    $stmt->execute($data);
} catch (\PDOException $e) {
    echo "Error: " . $e->getMessage();
    die($e->getTraceAsString());
}
```

---

## File Operations

### Safe Upload
```php
$errors = InputValidator::validateFileUpload($file, ['image/jpeg']);
if (!empty($errors)) {
    // Show errors
} else {
    $safeFilename = EncryptionHandler::generateRandomString(32) . '.jpg';
    move_uploaded_file($file['tmp_name'], "/uploads/$safeFilename");
    chmod("/uploads/$safeFilename", 0644);
}
```

### Safe File Display
```php
// ✅ CORRECT
$file = InputValidator::sanitizeFilename($_GET['file'] ?? '');
$path = "/uploads/$file";
if (file_exists($path) && is_file($path)) {
    echo file_get_contents($path);
}

// ❌ WRONG - Path traversal vulnerability
// $path = "/uploads/" . $_GET['file'];
```

---

## Session Management

### Create Session
```php
createUserSession([
    'id' => $user['id'],
    'username' => $user['username'],
    'email' => $user['email'],
    'role' => $user['role'],
    'full_name' => $user['full_name']
]);
```

### Check Login
```php
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}
$userId = getCurrentUserId();
$role = getCurrentUserRole();
```

### Logout
```php
destroySession();
header('Location: login.php');
exit;
```

---

## Logging Events

### Critical Event
```php
$logger->critical('Critical security event', [
    'type' => 'unauthorized_access',
    'resource' => 'admin_panel'
]);
```

### Failed Authentication
```php
$logger->logFailedAuth($username, 'invalid_credentials');
```

### Successful Authentication
```php
$logger->logSuccessfulAuth($user['id'], $user['username']);
```

### Permission Denied
```php
$logger->logPermissionDenied(
    $_SESSION['user_id'],
    'delete',
    'product_' . $id
);
```

### Suspicious Activity
```php
$logger->logSuspiciousActivity('sql_injection_attempt', [
    'pattern' => htmlspecialchars($_GET['q']),
    'source' => 'search_param'
]);
```

### File Upload
```php
$logger->logFileUpload(
    $_SESSION['user_id'],
    $filename,
    $_FILES['image']['type'],
    $_FILES['image']['size']
);
```

---

## Encryption

### Hash & Verify Password
```php
// When storing
$hash = EncryptionHandler::hashPassword($plainPassword);
// Save $hash to database

// When verifying
if (EncryptionHandler::verifyPassword($input, $hash)) {
    // Password correct
}
```

### Encrypt Sensitive Data
```php
$encryptor = new EncryptionHandler();
$encrypted = $encryptor->encrypt($creditCardNumber);
// Store $encrypted in database
```

### Decrypt Sensitive Data
```php
$decrypted = $encryptor->decrypt($encryptedData);
// Use $decrypted (immediately, don't store)
```

### Generate Secure Token
```php
$token = EncryptionHandler::generateToken(32);
```

---

## Headers & Meta

### Auto-added to Every Page
```
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
X-XSS-Protection: 1; mode=block
Content-Security-Policy: default-src 'self'; ...
Strict-Transport-Security: max-age=31536000
Referrer-Policy: strict-origin-when-cross-origin
```

### No need to add - included automatically via `security_headers.php`

---

## Testing Checklist

```
☐ Input validation prevents invalid data
☐ Output escaping works on all user content
☐ Rate limiting blocks brute force (5 tries/15 min)
☐ CSRF tokens prevent form hijacking
☐ Passwords are hashed (bcrypt)
☐ Sessions expire after 30 min idle
☐ HTTPS is enforced
☐ SQL errors don't show to users
☐ File uploads are validated
☐ Security logs are created
☐ rate_limiter table has entries
☐ security_logs table records events
```

---

## Common Commands

```bash
# Generate encryption key
php -r "echo base64_encode(openssl_random_pseudo_bytes(32));"

# Run setup wizard
php security_setup.php

# Check vulnerabilities
composer audit

# View logs
tail -f /var/log/inventory/security.log

# Check HTTPS headers
curl -I https://your-domain.com | grep "Strict-Transport\|X-Frame"
```

---

## Remember

✅ **Validate All Input** - Never trust user data  
✅ **Escape All Output** - Prevent XSS attacks  
✅ **Use Prepared Statements** - Prevent SQL injection  
✅ **Hash Passwords** - Use bcrypt, not MD5  
✅ **Enable HTTPS** - Encrypt all traffic  
✅ **Log Events** - Track security incidents  
✅ **Rate Limit** - Prevent brute force  
✅ **Check Permissions** - Enforce access control  

---

**Print this card and keep it at your desk! 🔐**
