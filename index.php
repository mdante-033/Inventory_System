<?php
/**
 * index.php — Entry point
 *
 * Works locally (XAMPP / WAMP / Laravel Herd) AND on any production host
 * (Render, Railway, Fly.io, shared hosting, VPS).
 *
 * Key fixes vs old version:
 *  1. session_start() guarded so luxestore.php can't start a second session.
 *  2. DB failure shows a styled error page instead of a blank screen.
 *  3. error_handler.php is optional — won't crash if the file is missing.
 *  4. luxestore.php session_start() is also guarded (see luxestore.php fix).
 */

// ── Error handling (optional file) ──────────────────────────────────────────
if (file_exists(__DIR__ . '/error_handler.php')) {
    require_once __DIR__ . '/error_handler.php';
} else {
    // Minimal safe defaults when error_handler.php is absent
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

// ── Session (exactly once, before anything else reads $_SESSION) ─────────────
if (session_status() === PHP_SESSION_NONE) {
    // Secure session cookie settings
    $cookieParams = [
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    session_set_cookie_params($cookieParams);
    session_start();
}

// ── Database connection ──────────────────────────────────────────────────────
require_once __DIR__ . '/db_connect.php';

// ── DB failure: show friendly error, never a blank page ─────────────────────
if (!isDBConnected()) {
    http_response_code(503);
    $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
            || php_uname('n') === gethostname();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>LuxeStore — Temporarily Unavailable</title>
        <style>
            *{margin:0;padding:0;box-sizing:border-box}
            body{font-family:system-ui,sans-serif;background:#0e0c09;color:#f5f0e8;
                 display:flex;align-items:center;justify-content:center;min-height:100vh;padding:2rem}
            .box{max-width:520px;width:100%;text-align:center}
            .logo{font-size:2.5rem;letter-spacing:.1em;color:#c9a84c;margin-bottom:2rem;
                  font-family:Georgia,serif;font-style:italic}
            h1{font-size:1.4rem;margin-bottom:1rem;color:#f5f0e8}
            p{color:rgba(245,240,232,.6);line-height:1.7;margin-bottom:1rem;font-size:.95rem}
            .badge{display:inline-block;background:rgba(201,168,76,.15);border:1px solid rgba(201,168,76,.3);
                   color:#c9a84c;padding:.4rem 1rem;border-radius:4px;font-size:.8rem;
                   letter-spacing:.1em;text-transform:uppercase;margin-bottom:1.5rem}
            .debug{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);
                   border-radius:6px;padding:1rem;margin-top:1.5rem;font-family:monospace;
                   font-size:.8rem;color:rgba(245,240,232,.5);text-align:left}
            .debug strong{color:#c9a84c}
        </style>
    </head>
    <body>
        <div class="box">
            <div class="logo">LuxeStore</div>
            <span class="badge">Temporarily Unavailable</span>
            <h1>We'll be right back</h1>
            <p>Our team has been notified and is working on a fix. Please try again in a few moments.</p>
            <?php if ($isLocal): ?>
            <div class="debug">
                <strong>Local dev checklist:</strong><br><br>
                1. Is PostgreSQL running? (Check XAMPP / pgAdmin / brew services)<br>
                2. Does the database exist? <code>CREATE DATABASE "Inventory_DB"</code><br>
                3. Are your credentials set?<br>
                &nbsp;&nbsp;&nbsp;DB_HOST=localhost &nbsp;DB_PORT=5432<br>
                &nbsp;&nbsp;&nbsp;DB_NAME=Inventory_DB &nbsp;DB_USER=postgres<br>
                &nbsp;&nbsp;&nbsp;DB_PASSWORD=yourpassword<br><br>
                Set these in a <code>.env</code> file or in your system environment.<br><br>
                <strong>Error:</strong> <?= htmlspecialchars($db_connection_error ?? 'Unknown') ?>
            </div>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ── Session helpers (included by session.php — but guard in case it's absent) 
if (file_exists(__DIR__ . '/includes/session.php')) {
    require_once __DIR__ . '/includes/session.php';
}

// ── Landing page ─────────────────────────────────────────────────────────────
require __DIR__ . '/luxestore.php';
