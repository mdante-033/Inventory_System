<?php
/**
 * Application Constants
 * Global constants for the Inventory System
 *
 * All define() calls wrapped with if (!defined()) to prevent
 * "Constant already defined" warnings when this file is included
 * more than once across different entry points.
 */

// ── Database Tables ───────────────────────────────────────────────────────
if (!defined('TABLE_USERS'))       define('TABLE_USERS',       'users');
if (!defined('TABLE_CATEGORIES'))  define('TABLE_CATEGORIES',  'categories');
if (!defined('TABLE_PRODUCTS'))    define('TABLE_PRODUCTS',    'products');
if (!defined('TABLE_STOCK_LOGS'))  define('TABLE_STOCK_LOGS',  'stock_logs');
if (!defined('TABLE_ORDERS'))      define('TABLE_ORDERS',      'orders');
if (!defined('TABLE_ORDER_ITEMS')) define('TABLE_ORDER_ITEMS', 'order_items');
if (!defined('TABLE_PAYMENTS'))    define('TABLE_PAYMENTS',    'payments');

// ── User Roles ────────────────────────────────────────────────────────────
if (!defined('ROLE_ADMIN'))        define('ROLE_ADMIN',        'admin');
if (!defined('ROLE_MANAGER'))      define('ROLE_MANAGER',      'manager');
if (!defined('ROLE_STAFF'))        define('ROLE_STAFF',        'staff');
if (!defined('ROLE_CUSTOMER'))     define('ROLE_CUSTOMER',     'customer');

// ── User Roles Array ──────────────────────────────────────────────────────
if (!defined('USER_ROLES')) define('USER_ROLES', [
    ROLE_ADMIN    => 'Administrator',
    ROLE_MANAGER  => 'Manager',
    ROLE_STAFF    => 'Staff',
    ROLE_CUSTOMER => 'Customer',
]);

// ── Stock Actions ─────────────────────────────────────────────────────────
if (!defined('STOCK_ACTION_ADD'))      define('STOCK_ACTION_ADD',      'add');
if (!defined('STOCK_ACTION_REMOVE'))   define('STOCK_ACTION_REMOVE',   'remove');
if (!defined('STOCK_ACTION_ADJUST'))   define('STOCK_ACTION_ADJUST',   'adjust');
if (!defined('STOCK_ACTION_SALE'))     define('STOCK_ACTION_SALE',     'sale');
if (!defined('STOCK_ACTION_RETURN'))   define('STOCK_ACTION_RETURN',   'return');
if (!defined('STOCK_ACTION_TRANSFER')) define('STOCK_ACTION_TRANSFER', 'transfer');

// ── Stock Status ──────────────────────────────────────────────────────────
if (!defined('STATUS_IN_STOCK'))     define('STATUS_IN_STOCK',     'in_stock');
if (!defined('STATUS_LOW_STOCK'))    define('STATUS_LOW_STOCK',    'low_stock');
if (!defined('STATUS_OUT_OF_STOCK')) define('STATUS_OUT_OF_STOCK', 'out_of_stock');
if (!defined('STATUS_OVERSTOCKED'))  define('STATUS_OVERSTOCKED',  'overstocked');

// ── Order Status ──────────────────────────────────────────────────────────
if (!defined('ORDER_STATUS_PENDING'))    define('ORDER_STATUS_PENDING',    'pending');
if (!defined('ORDER_STATUS_PROCESSING')) define('ORDER_STATUS_PROCESSING', 'processing');
if (!defined('ORDER_STATUS_COMPLETED'))  define('ORDER_STATUS_COMPLETED',  'completed');
if (!defined('ORDER_STATUS_CANCELLED'))  define('ORDER_STATUS_CANCELLED',  'cancelled');
if (!defined('ORDER_STATUS_REFUNDED'))   define('ORDER_STATUS_REFUNDED',   'refunded');

// ── Payment Status ────────────────────────────────────────────────────────
if (!defined('PAYMENT_STATUS_PENDING'))   define('PAYMENT_STATUS_PENDING',   'pending');
if (!defined('PAYMENT_STATUS_COMPLETED')) define('PAYMENT_STATUS_COMPLETED', 'completed');
if (!defined('PAYMENT_STATUS_FAILED'))    define('PAYMENT_STATUS_FAILED',    'failed');
if (!defined('PAYMENT_STATUS_REFUNDED'))  define('PAYMENT_STATUS_REFUNDED',  'refunded');

// ── Payment Methods ───────────────────────────────────────────────────────
if (!defined('PAYMENT_METHOD_MPESA')) define('PAYMENT_METHOD_MPESA', 'mpesa');
if (!defined('PAYMENT_METHOD_CARD'))  define('PAYMENT_METHOD_CARD',  'card');
if (!defined('PAYMENT_METHOD_CASH'))  define('PAYMENT_METHOD_CASH',  'cash');
if (!defined('PAYMENT_METHOD_BANK'))  define('PAYMENT_METHOD_BANK',  'bank');

// ── M-Pesa ────────────────────────────────────────────────────────────────
if (!defined('MPESA_ENVIRONMENT'))    define('MPESA_ENVIRONMENT',    'sandbox');
if (!defined('MPESA_SHORTCODE'))      define('MPESA_SHORTCODE',      getenv('MPESA_SHORTCODE')       ?: '174379');
if (!defined('MPESA_CONSUMER_KEY'))   define('MPESA_CONSUMER_KEY',   getenv('MPESA_CONSUMER_KEY')    ?: 'your_consumer_key');
if (!defined('MPESA_CONSUMER_SECRET'))define('MPESA_CONSUMER_SECRET',getenv('MPESA_CONSUMER_SECRET') ?: 'your_consumer_secret');
if (!defined('MPESA_PASSKEY'))        define('MPESA_PASSKEY',        getenv('MPESA_PASSKEY')         ?: 'your_passkey');
if (!defined('MPESA_CALLBACK_URL'))   define('MPESA_CALLBACK_URL',   getenv('MPESA_CALLBACK_URL')    ?: 'http://localhost/api/mpesa/callback.php');

// ── Application Settings ──────────────────────────────────────────────────
if (!defined('APP_NAME'))     define('APP_NAME',     getenv('APP_NAME')     ?: 'Inventory System');
if (!defined('APP_VERSION'))  define('APP_VERSION',  '1.0.0');
if (!defined('APP_URL'))      define('APP_URL',      getenv('APP_URL')      ?: 'http://localhost');
if (!defined('APP_TIMEZONE')) define('APP_TIMEZONE', getenv('APP_TIMEZONE') ?: 'Africa/Nairobi');
if (!defined('APP_LOCALE'))   define('APP_LOCALE',   'en_US');

// ── Pagination ────────────────────────────────────────────────────────────
if (!defined('ITEMS_PER_PAGE'))         define('ITEMS_PER_PAGE',         25);
if (!defined('ITEMS_PER_PAGE_OPTIONS')) define('ITEMS_PER_PAGE_OPTIONS', [10, 25, 50, 100]);

// ── Session ───────────────────────────────────────────────────────────────
if (!defined('SESSION_TIMEOUT'))  define('SESSION_TIMEOUT',  1800); // 30 minutes
if (!defined('REMEMBER_ME_DAYS')) define('REMEMBER_ME_DAYS', 30);

// ── Security ──────────────────────────────────────────────────────────────
if (!defined('HASH_ALGORITHM'))    define('HASH_ALGORITHM',    'bcrypt');
if (!defined('MIN_PASSWORD_LENGTH'))define('MIN_PASSWORD_LENGTH', 8);
if (!defined('MAX_LOGIN_ATTEMPTS'))define('MAX_LOGIN_ATTEMPTS', 5);
if (!defined('LOCKOUT_DURATION'))  define('LOCKOUT_DURATION',  900); // 15 minutes

// ── File Upload ───────────────────────────────────────────────────────────
if (!defined('MAX_UPLOAD_SIZE'))      define('MAX_UPLOAD_SIZE',      5242880); // 5MB
if (!defined('ALLOWED_IMAGE_TYPES'))  define('ALLOWED_IMAGE_TYPES',  ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
if (!defined('UPLOAD_PATH'))          define('UPLOAD_PATH',          __DIR__ . '/../uploads/');

// ── Date / Time Formats ───────────────────────────────────────────────────
if (!defined('DATE_FORMAT'))             define('DATE_FORMAT',             'Y-m-d');
if (!defined('TIME_FORMAT'))             define('TIME_FORMAT',             'H:i:s');
if (!defined('DATETIME_FORMAT'))         define('DATETIME_FORMAT',         'Y-m-d H:i:s');
if (!defined('DISPLAY_DATE_FORMAT'))     define('DISPLAY_DATE_FORMAT',     'M d, Y');
if (!defined('DISPLAY_DATETIME_FORMAT')) define('DISPLAY_DATETIME_FORMAT', 'M d, Y H:i');

// ── Currency ──────────────────────────────────────────────────────────────
if (!defined('DEFAULT_CURRENCY')) define('DEFAULT_CURRENCY', 'KES');
if (!defined('CURRENCY_SYMBOL'))  define('CURRENCY_SYMBOL',  'KSh ');
if (!defined('DECIMAL_PLACES'))   define('DECIMAL_PLACES',   2);

// ── Error Codes ───────────────────────────────────────────────────────────
if (!defined('ERROR_NONE'))          define('ERROR_NONE',          0);
if (!defined('ERROR_GENERAL'))       define('ERROR_GENERAL',       1);
if (!defined('ERROR_NOT_FOUND'))     define('ERROR_NOT_FOUND',     2);
if (!defined('ERROR_UNAUTHORIZED'))  define('ERROR_UNAUTHORIZED',  3);
if (!defined('ERROR_FORBIDDEN'))     define('ERROR_FORBIDDEN',     4);
if (!defined('ERROR_VALIDATION'))    define('ERROR_VALIDATION',    5);
if (!defined('ERROR_DATABASE'))      define('ERROR_DATABASE',      6);
if (!defined('ERROR_PAYMENT'))       define('ERROR_PAYMENT',       7);

// ── Status Codes ──────────────────────────────────────────────────────────
if (!defined('STATUS_ACTIVE'))   define('STATUS_ACTIVE',   1);
if (!defined('STATUS_INACTIVE')) define('STATUS_INACTIVE', 0);
if (!defined('STATUS_DELETED'))  define('STATUS_DELETED',  -1);

// ── Email / Mail Settings ─────────────────────────────────────────────────
if (!defined('MAIL_ENABLED'))       define('MAIL_ENABLED',       false);
if (!defined('MAIL_DRIVER'))        define('MAIL_DRIVER',        'smtp');
if (!defined('MAIL_HOST'))          define('MAIL_HOST',          getenv('MAIL_HOST')          ?: 'smtp.mailtrap.io');
if (!defined('MAIL_PORT'))          define('MAIL_PORT',          getenv('MAIL_PORT')          ?: 2525);
if (!defined('MAIL_USERNAME'))      define('MAIL_USERNAME',      getenv('MAIL_USERNAME')      ?: '');
if (!defined('MAIL_PASSWORD'))      define('MAIL_PASSWORD',      getenv('MAIL_PASSWORD')      ?: '');
if (!defined('MAIL_FROM_ADDRESS'))  define('MAIL_FROM_ADDRESS',  getenv('MAIL_FROM_ADDRESS')  ?: 'noreply@inventorysystem.com');
if (!defined('MAIL_FROM_NAME'))     define('MAIL_FROM_NAME',     getenv('MAIL_FROM_NAME')     ?: 'Inventory System');

// ── API Settings ──────────────────────────────────────────────────────────
if (!defined('API_ENABLED')) define('API_ENABLED', true);
if (!defined('API_VERSION')) define('API_VERSION', 'v1');
if (!defined('API_PREFIX'))  define('API_PREFIX',  '/api');

// ── Logging ───────────────────────────────────────────────────────────────
if (!defined('LOG_ENABLED')) define('LOG_ENABLED', true);
if (!defined('LOG_PATH'))    define('LOG_PATH',    __DIR__ . '/../logs/');
if (!defined('LOG_LEVEL'))   define('LOG_LEVEL',   'error');

// ── Cache ─────────────────────────────────────────────────────────────────
if (!defined('CACHE_ENABLED'))   define('CACHE_ENABLED',   false);
if (!defined('CACHE_PATH'))      define('CACHE_PATH',      __DIR__ . '/../cache/');
if (!defined('CACHE_LIFETIME'))  define('CACHE_LIFETIME',  3600);

// ── CSRF Token ────────────────────────────────────────────────────────────
if (!defined('CSRF_TOKEN_NAME'))   define('CSRF_TOKEN_NAME',   'csrf_token');
if (!defined('CSRF_TOKEN_LENGTH')) define('CSRF_TOKEN_LENGTH', 32);

// ── Set timezone ──────────────────────────────────────────────────────────
date_default_timezone_set(APP_TIMEZONE);
