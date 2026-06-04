<?php
// Global error handling must be first
require_once __DIR__ . '/error_handler.php';

// Database connection (sets global $pdo)
require_once __DIR__ . '/db_connect.php';

// Check if database is available before proceeding
if (!isDBConnected()) {
    http_response_code(503);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Database Connection Error</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; min-height: 100vh; }
            .error-container { background: white; padding: 3rem; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); max-width: 600px; }
            .error-icon { font-size: 3rem; margin-bottom: 1.5rem; }
            h1 { color: #d63031; margin-bottom: 1rem; font-size: 1.8rem; }
            p { color: #555; line-height: 1.8; margin-bottom: 1rem; }
            .error-details { background: #f8f9fa; border-left: 4px solid #d63031; padding: 1rem; margin: 1.5rem 0; border-radius: 4px; font-family: monospace; font-size: 0.9rem; color: #666; }
            .status-badge { display: inline-block; background: #ffc107; color: #000; padding: 0.5rem 1rem; border-radius: 20px; margin-bottom: 1.5rem; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">🚨</div>
            <span class="status-badge">System Maintenance</span>
            <h1>Service Unavailable</h1>
            <p>The inventory system is temporarily unavailable due to a database connection issue.</p>
            <p>Our team has been notified and is working on a fix. Please try again in a few moments.</p>
            <div class="error-details">
                <strong>Technical Details:</strong><br>
                Database: PostgreSQL<br>
                Status: Connection Failed<br>
                Check: /health for more information
            </div>
            <p style="color: #999; font-size: 0.9rem; margin-top: 2rem;">
                Error ID: <?php echo date('YmdHis') . '-' . substr(md5(microtime()), 0, 8); ?>
            </p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Session management (requires database to already be set up)
require_once __DIR__ . '/includes/session.php';

// Main application logic
require __DIR__ . '/luxestore.php';
