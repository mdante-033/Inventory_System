# 🔐 Security Implementation Guide

## Overview

This Inventory System has been enhanced with **comprehensive enterprise-grade security features**. All code is production-ready and follows OWASP Top 10 guidelines.

## 📦 What's Included

### Security Modules (Ready to Use)
1. **Security Headers** - Global protection against common web attacks
2. **Input Validators** - Comprehensive input validation for all data types
3. **Output Escaping** - Safe output rendering in HTML, JSON, CSS, JS contexts
4. **Encryption Handler** - AES-256-GCM encryption + bcrypt hashing
5. **Rate Limiter** - Brute-force protection for login & API endpoints
6. **Security Logger** - File & database event logging
7. **Enhanced Sessions** - Secure cookie flags & session management

### Database Tables
10 new security-focused tables created:
- `login_attempts` - Track failed/successful logins
- `api_requests` - API usage tracking
- `audit_logs` - Database activity audit trail
- `security_logs` - Security events
- `user_2fa` - 2FA data (ready for implementation)
- `password_history` - Prevent password reuse
- `session_activity` - Active session tracking
- `encryption_keys` - Key rotation
- `security_incidents` - Incident tracking
- `api_keys` - External API access

### Configuration Files
- `.htaccess` - Apache security hardening
- `config/nginx.conf.example` - Nginx configuration
- `config/php.ini.example` - PHP security settings

---

## 🚀 Quick Start (5 minutes)

### Step 1: Run Setup Wizard
```bash
php security_setup.php
```

This will:
- ✅ Generate encryption key
- ✅ Create database tables
- ✅ Test encryption
- ✅ Provide configuration checklist

### Step 2: Update .env File
```env
# Copy the encryption key from setup output
ENCRYPTION_KEY=<base64-key-from-setup>

# Configure environment
APP_ENV=production
APP_DEBUG=false

# Optional session settings
SESSION_IDLE_TIMEOUT=1800        # 30 minutes
SESSION_ABSOLUTE_TIMEOUT=3600    # 1 hour
SESSION_ROTATE_INTERVAL=300      # 5 minutes

# Log path
SECURITY_LOG_PATH=/var/log/inventory/security.log
```

### Step 3: Copy Configuration Files
**For Apache:**
```bash
# .htaccess is already in root, just enable rewrite module
sudo a2enmod rewrite headers
sudo systemctl restart apache2
```

**For Nginx:**
```bash
# Copy settings from config/nginx.conf.example to your server block
sudo cp config/nginx.conf.example /etc/nginx/sites-available/default
sudo nginx -t && sudo systemctl restart nginx
```

### Step 4: Update php.ini
```bash
# Copy PHP settings
sudo cp config/php.ini.example /etc/php/8.2/apache2/php.ini
# Or merge the important settings manually
```

### Step 5: Test HTTPS
```bash
# Verify HTTPS is working
curl -I https://your-domain.com

# Check security headers
curl -I https://your-domain.com | grep Strict-Transport
```

### Step 6: Test Rate Limiting
Login and try invalid password 5 times - you'll see rate limit on 6th attempt.

---

## 📚 Integration Guide

### For Existing Pages

Add security to any page in 3 steps:

#### Step 1: Include Security Modules (Top of Page)
```php
<?php
require_once __DIR__ . '/includes/session.php';           // Includes security headers
require_once __DIR__ . '/includes/validators.php';
require_once __DIR__ . '/includes/output.php';
require_once __DIR__ . '/includes/encryption.php';
require_once __DIR__ . '/includes/security_logger.php';
```

#### Step 2: Initialize Logger (Optional)
```php
<?php
require_once __DIR__ . '/db_connect.php';

$securityLogger = new SecurityLogger($pdo);
```

#### Step 3: Use in Your Code
```php
<?php
// Validate input
$email = InputValidator::sanitizeEmail($_POST['email'] ?? '');
if (!InputValidator::validateEmail($email)) {
    $securityLogger->warning('Invalid email format');
}

// Escape output
echo OutputEscaper::e($userInput); // Safe HTML output

// Hash password
$hash = EncryptionHandler::hashPassword($password);

// Check rate limit
$limiter = new RateLimiter($pdo, $username);
if ($limiter->isLimited()) {
    die('Too many attempts');
}
?>
```

---

## 🔐 Security Features Explained

### 1. Input Validation
```php
// Validate different data types
InputValidator::validateEmail($email);              // RFC 5322 compliant
InputValidator::validatePassword($pwd);              // Strength check
InputValidator::validateUsername($user);             // Alphanumeric + special
InputValidator::validateInt($id, 1, 1000);           // Range validation
InputValidator::validateFileUpload($file, $types);   // MIME + content check
InputValidator::validatePhone($phone);               // E.164 format
```

**When to use**: Every user input - forms, APIs, GET/POST parameters

### 2. Output Escaping
```php
// Escape for different contexts
OutputEscaper::e($text);                    // HTML (most common)
OutputEscaper::escapeAttr($text);           // HTML attributes
OutputEscaper::escapeJs($data);             // JavaScript (JSON)
OutputEscaper::safeUrl($url);               // URLs in href
OutputEscaper::encodeJson($data);           // JSON responses
```

**When to use**: Every time you output user data to browser

### 3. Encryption
```php
// Passwords (bcrypt)
$hash = EncryptionHandler::hashPassword($password);
if (EncryptionHandler::verifyPassword($input, $hash)) { }

// Sensitive data (AES-256-GCM)
$encryptor = new EncryptionHandler();
$encrypted = $encryptor->encrypt($cardNumber);
$decrypted = $encryptor->decrypt($encrypted);
```

**When to use**: Passwords always, sensitive fields optionally

### 4. Rate Limiting
```php
$limiter = new RateLimiter($pdo, $identifier, $ip);

// Check if rate limited
if ($limiter->isLimited(5, 15)) {  // 5 attempts per 15 min
    echo "Too many attempts";
}

// Record attempt
$limiter->recordAttempt(false);  // Failed
$limiter->recordAttempt(true);   // Success (clears counter)
```

**When to use**: Login, password reset, API endpoints, file uploads

### 5. Security Logging
```php
$logger = new SecurityLogger($pdo);

// Log events
$logger->critical('Critical issue');
$logger->warning('Warning');
$logger->info('Info');

// Log specific events
$logger->logFailedAuth($username);
$logger->logSuccessfulAuth($userId, $username);
$logger->logPermissionDenied($userId, 'delete', 'resource_id');
$logger->logSuspiciousActivity('sql_injection_attempt');
$logger->logFileUpload($userId, $filename, $mime, $size);
$logger->logApiRequest($method, $endpoint, $status);
```

**When to use**: After critical operations, security events, suspicious activity

### 6. Security Headers
All pages automatically get:
- **CSP** - Content Security Policy (prevents XSS)
- **HSTS** - Forces HTTPS
- **X-Frame-Options** - Prevents clickjacking
- **X-Content-Type-Options** - Prevents MIME sniffing
- **Referrer-Policy** - Controls referrer information
- **Permissions-Policy** - Disables dangerous features

---

## 📊 Real-World Examples

### Example 1: Secure Form Submission
```php
<?php
require_once 'includes/session.php';
require_once 'includes/validators.php';
require_once 'includes/output.php';
require_once 'includes/security_logger.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$logger = new SecurityLogger($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $logger->logSuspiciousActivity('csrf_failed');
        die('Security error');
    }

    // Validate input
    $name = InputValidator::sanitizeString($_POST['name'] ?? '');
    $email = InputValidator::sanitizeEmail($_POST['email'] ?? '');

    if (!InputValidator::validateEmail($email)) {
        echo "Invalid email";
    } else {
        // Process...
        $logger->info('Form submitted', ['user_id' => $_SESSION['user_id']]);
    }
}

// In HTML:
?>
<form method="POST">
    <input type="hidden" name="csrf_token" value="<?php echo OutputEscaper::escapeAttr($csrfToken); ?>">
    <input type="email" name="email">
    <button type="submit">Submit</button>
</form>
```

### Example 2: Secure File Upload
```php
<?php
if (isset($_FILES['avatar'])) {
    $errors = InputValidator::validateFileUpload(
        $_FILES['avatar'],
        ['image/jpeg', 'image/png'],
        2097152  // 2MB
    );

    if (!empty($errors)) {
        foreach ($errors as $error) {
            echo OutputEscaper::e($error);
        }
    } else {
        $filename = EncryptionHandler::generateRandomString(32) 
                  . '.' . pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
        move_uploaded_file($_FILES['avatar']['tmp_name'], "/uploads/$filename");
        $logger->logFileUpload($_SESSION['user_id'], $filename, 
                              $_FILES['avatar']['type'], $_FILES['avatar']['size']);
    }
}
?>
```

### Example 3: Secure API Endpoint
```php
<?php
header('Content-Type: application/json');
require_once 'includes/validators.php';
require_once 'includes/output.php';
require_once 'includes/rate_limiter.php';

// Rate limit
$limiter = new RateLimiter($pdo);
if (!$limiter->checkApiLimit($_GET['api_key'] ?? '', 60)) {
    http_response_code(429);
    echo OutputEscaper::encodeJson(['error' => 'Rate limited']);
    exit;
}

// Validate input
$id = InputValidator::validatePositiveInt($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo OutputEscaper::encodeJson(['error' => 'Invalid ID']);
    exit;
}

// Fetch and return
$stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
$stmt->execute([$id]);
$result = $stmt->fetch();

echo OutputEscaper::encodeJson($result);
?>
```

---

## 🔒 Best Practices

### Do's ✅
- ✅ Validate ALL user input
- ✅ Escape ALL user output
- ✅ Use prepared statements (PDO)
- ✅ Hash passwords with bcrypt
- ✅ Enable HTTPS everywhere
- ✅ Log security events
- ✅ Use CSRF tokens on forms
- ✅ Keep dependencies updated
- ✅ Follow OWASP guidelines

### Don'ts ❌
- ❌ Never display error stack traces
- ❌ Never concatenate SQL queries
- ❌ Never store plaintext passwords
- ❌ Never disable HTTPS
- ❌ Never expose sensitive files (.env, .git)
- ❌ Never trust user input
- ❌ Never use eval() or similar
- ❌ Never commit secrets to git

---

## 📋 Pre-Deployment Checklist

Before going live, verify:

- [ ] Encryption key generated and in .env
- [ ] HTTPS enabled and working
- [ ] .htaccess or nginx.conf deployed
- [ ] php.ini security settings applied
- [ ] Security log directory writable
- [ ] Database tables created (run security_setup.php)
- [ ] Email configured for verification/password reset
- [ ] Backups automated and tested
- [ ] Monitoring/alerting configured
- [ ] Regular security audits scheduled
- [ ] Developer documentation updated
- [ ] Staff security training completed

---

## 🚨 Incident Response

### If Breach is Suspected

1. **Immediately isolate** the affected system
2. **Check security logs** in `/var/log/inventory/security.log`
3. **Review audit trail** in `security_logs` and `audit_logs` tables
4. **Identify affected users** and notify them
5. **Reset all passwords** for affected accounts
6. **Force re-authentication** for all sessions
7. **Audit code changes** for unauthorized modifications
8. **Restore from clean backup** if necessary
9. **Document everything** for post-incident analysis
10. **Implement preventive measures** to avoid recurrence

---

## 📞 Support & Troubleshooting

### Rate Limiting Isn't Working
- Verify `login_attempts` table exists
- Check database connection in security_logger
- Review error logs

### Encryption Key Error
- Run `security_setup.php` to generate new key
- Update .env with new ENCRYPTION_KEY
- Test with a fresh login

### Headers Not Showing
- Check `security_headers.php` is included first
- Verify no output before `require` statements
- Check `.htaccess` is in document root

### Files Can't Be Uploaded
- Check `uploads/` directory permissions (755)
- Verify MIME types match allowed list
- Check file size limit in php.ini

---

## 🔄 Maintenance

### Monthly
```bash
composer audit  # Check for vulnerable packages
```

### Quarterly
- Review security logs for patterns
- Penetration test critical features
- Update dependencies

### Annually
- Professional security assessment
- Incident analysis and review
- Security training for team

---

## 📚 Additional Resources

- **OWASP Top 10**: https://owasp.org/www-project-top-ten/
- **PHP Security Manual**: https://www.php.net/manual/en/security.php
- **CSP Guide**: https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP
- **NIST Guidelines**: https://www.nist.gov/cyberframework

---

## 📄 Documentation Files

| File | Purpose |
|------|---------|
| `SECURITY_CHECKLIST.md` | Detailed 9-phase implementation plan |
| `SECURITY_IMPLEMENTATION.md` | Features & usage documentation |
| `SECURITY_EXAMPLES.php` | Code examples & patterns |
| `README.md` | Original setup guide |

---

## ✅ Implementation Status

All 4 requirements completed:

1. ✅ **Create helper classes** - All security modules ready
2. ✅ **Create database tables** - 10 tables with security logging
3. ✅ **Add security headers** - Global headers on every page
4. ✅ **Integrate & enhance** - Login, file uploads, API endpoints

**Status**: Production-ready and fully secured  
**Last Updated**: 2026-05-20  
**Version**: 1.0

---

For questions or issues, refer to the detailed documentation files included with this implementation.
