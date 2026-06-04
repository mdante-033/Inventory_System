<?php
/**
 * Security Headers - Global security policy headers
 * Include this file at the very top of every page
 */

// Prevent multiple header calls
if (!headers_sent()) {
    // Content Security Policy - Strict for maximum protection
    $csp = "default-src 'self'; "
         . "script-src 'self' https://cdn.jsdelivr.net https://code.jquery.com; "
         . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; "
         . "img-src 'self' data: https:; "
         . "font-src 'self' https://fonts.gstatic.com; "
         . "connect-src 'self'; "
         . "frame-ancestors 'none'; "
         . "base-uri 'self'; "
         . "form-action 'self'; "
         . "upgrade-insecure-requests; "
         . "block-all-mixed-content";

    header('Content-Security-Policy: ' . $csp);
    header('Content-Security-Policy-Report-Only: ' . $csp); // Also log violations

    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');

    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Frame-Options: DENY'); // Stricter version

    // Enable XSS protection in older browsers
    header('X-XSS-Protection: 1; mode=block');

    // Referrer Policy - Strict
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Permissions Policy (formerly Feature Policy)
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()');

    // HSTS - Force HTTPS
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }

    // Set content type to prevent encoding issues
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '.json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
    } else {
        header('Content-Type: text/html; charset=utf-8');
    }

    // Cache control - No cache for sensitive pages
    if (strpos($_SERVER['REQUEST_URI'] ?? '', 'admin') !== false || 
        strpos($_SERVER['REQUEST_URI'] ?? '', 'dashboard') !== false ||
        strpos($_SERVER['REQUEST_URI'] ?? '', 'login') !== false) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    }

    // Remove sensitive headers
    header_remove('Server');
    header_remove('X-Powered-By');
    header_remove('X-AspNet-Version');

    // Additional security headers
    header('X-Content-Type-Options: nosniff');
    header('X-Permitted-Cross-Domain-Policies: none');
}
?>
