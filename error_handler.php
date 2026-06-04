<?php
/**
 * Global Error & Exception Handler
 * Prevents blank 500 errors by catching and logging all errors gracefully
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Ensure log directory exists
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

// Set error log path
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

/**
 * Custom error handler
 */
function handleError($errno, $errstr, $errfile, $errline) {
    $error_types = [
        E_ERROR             => 'Fatal Error',
        E_WARNING           => 'Warning',
        E_PARSE             => 'Parse Error',
        E_NOTICE            => 'Notice',
        E_CORE_ERROR        => 'Core Error',
        E_CORE_WARNING      => 'Core Warning',
        E_COMPILE_ERROR     => 'Compile Error',
        E_COMPILE_WARNING   => 'Compile Warning',
        E_USER_ERROR        => 'User Error',
        E_USER_WARNING      => 'User Warning',
        E_USER_NOTICE       => 'User Notice',
        E_STRICT            => 'Strict',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
        E_DEPRECATED        => 'Deprecated',
        E_USER_DEPRECATED   => 'User Deprecated',
    ];

    $error_type = $error_types[$errno] ?? 'Unknown Error';
    $message = "[{$error_type}] {$errstr} in {$errfile} on line {$errline}";
    
    error_log($message);
    
    // For fatal errors, show user-friendly message
    if (in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        http_response_code(500);
        displayErrorPage('An unexpected error occurred. Our team has been notified.');
        exit;
    }
}

/**
 * Custom exception handler
 */
function handleException($exception) {
    $message = $exception->getMessage();
    $file = $exception->getFile();
    $line = $exception->getLine();
    
    error_log("[Exception] {$message} in {$file} on line {$line}");
    
    http_response_code(500);
    displayErrorPage('An unexpected error occurred. Our team has been notified.');
    exit;
}

/**
 * Display error page to user
 */
function displayErrorPage($message = 'An unexpected error occurred.') {
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(500);
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Oops! Something went wrong</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
            .error-container { background: white; padding: 3rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 500px; text-align: center; }
            .error-icon { font-size: 4rem; margin-bottom: 1.5rem; }
            h1 { color: #333; margin-bottom: 1rem; font-size: 2rem; }
            p { color: #666; line-height: 1.6; margin-bottom: 2rem; }
            .actions { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; }
            a, button { padding: 0.75rem 1.5rem; border-radius: 4px; border: none; cursor: pointer; text-decoration: none; font-size: 1rem; transition: background 0.3s; }
            .btn-primary { background: #007bff; color: white; }
            .btn-primary:hover { background: #0056b3; }
            .btn-secondary { background: #6c757d; color: white; }
            .btn-secondary:hover { background: #545b62; }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">⚠️</div>
            <h1>Oops!</h1>
            <p><?php echo htmlspecialchars($message); ?></p>
            <div class="actions">
                <a href="/" class="btn-primary">Go Home</a>
                <button class="btn-secondary" onclick="location.reload()">Try Again</button>
            </div>
        </div>
    </body>
    </html>
    <?php
}

// Register handlers
set_error_handler('handleError', E_ALL);
set_exception_handler('handleException');
