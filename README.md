-# Inventory System - Setup Instructions

A PHP-based Inventory Management System with user authentication and PostgreSQL support.

## File Structure

Typical project structure:

```text
project-root/
|-- sql/                         # Database schema or seed files
|-- config/                      # Application configuration
|-- includes/                    # Shared bootstrap/auth/session helpers
|-- modules/                     # Feature modules
|-- login.php                    # Login page
|-- logout.php                   # Logout handler
|-- run_setup.php                # One-time Render database installer
`-- README.md                    # This file
```

## Database Information

- **Database Engine**: PostgreSQL
- **Database Name**: `"Inventory_DB"`
- **Typical Tables**:
  - `users` - User authentication and roles
  - `categories` - Product categories
  - `products` - Inventory items
  - `stock_logs` - Inventory audit trail

### First Admin User

The one-time Render installer creates an administrator from these environment
variables when they are present:

- `ADMIN_USERNAME`
- `ADMIN_PASSWORD`
- `ADMIN_EMAIL`
- `ADMIN_NAME`

If those are not set, the installer creates `admin` / `admin123`. Change that
password immediately after setup, then delete `run_setup.php` from GitHub and
redeploy.

For a manual setup, create your first administrator by:

1. Generating a strong password hash with PHP.
2. Inserting an admin record with your own username, email, and password hash.
3. Storing the plain-text password only in your password manager, not in source control.

Example password hash generation:

```php
<?php
echo password_hash('<choose-a-strong-password>', PASSWORD_DEFAULT);
```

Example PostgreSQL insert:

```sql
INSERT INTO users (username, password, email, full_name, role)
VALUES (
    '<admin_username>',
    '<bcrypt_hash_generated_in_php>',
    '<admin_email>',
    '<admin_full_name>',
    'admin'
);
```

## Quick Setup Guide

### Step 1: Prepare Your Environment

1. Install PHP 8.x or later and enable the `pdo_pgsql` extension.
2. Install PostgreSQL 14 or later.
3. Place the application in your web server's document root or configured project directory.
4. If you use XAMPP, note that PostgreSQL is managed separately from the XAMPP control panel.

### Step 2: Create the PostgreSQL Database

Create the database with quoted casing:

```sql
CREATE DATABASE "Inventory_DB" ENCODING 'UTF8';
```

You can do this with `psql`, pgAdmin, or your preferred PostgreSQL client.

### Step 3: Import the Schema

Import the project schema or run the setup process provided by the application.

If you are importing SQL manually, use PostgreSQL-compatible schema files and verify that:

- `SERIAL` or identity columns are used instead of `AUTO_INCREMENT`
- PostgreSQL data types are used
- Foreign keys and indexes are created successfully

Example table definition:

```sql
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'staff',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

### Step 4: Configure the Application

Set database credentials through environment variables instead of hardcoding them.

Recommended variables:

```env
DB_HOST=localhost
DB_PORT=5432
DB_NAME=Inventory_DB
DB_USER=<your_postgres_user>
DB_PASSWORD=<your_postgres_password>
APP_ENV=local
APP_DEBUG=false
```

Example PostgreSQL PDO connection:

```php
<?php
$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '5432';
$dbName = getenv('DB_NAME') ?: 'Inventory_DB';
$username = getenv('DB_USER') ?: '';
$password = getenv('DB_PASSWORD') ?: '';

$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $dbName);

$pdo = new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
```

### Step 5: Access the Login Page

Open your application's login route in the browser, for example:

```text
http://localhost/<your-project-directory>/login.php
```

### Step 6: Sign In

Sign in with the administrator account you created during setup. No default username or password is provided.

## Configuration

### Secure Configuration Approach

Use this pattern for local and production configuration:

1. Commit a template such as `.env.example` or `config/database.php.example`.
2. Copy the template to a real runtime file such as `.env` or `config/database.php`.
3. Fill in environment-specific credentials locally.
4. Keep the real file out of version control.

Example template file:

```env
DB_HOST=localhost
DB_PORT=5432
DB_NAME=Inventory_DB
DB_USER=
DB_PASSWORD=
APP_ENV=local
APP_DEBUG=false
```

### .gitignore Recommendations

Make sure your `.gitignore` excludes:

```gitignore
.env
.env.local
.env.production
config/database.php
config/*.local.php
*.log
*.sql
*.dump
```

Do not commit:

- Real usernames or passwords
- Password hashes for shared/demo users
- Connection strings containing secrets
- Database exports with production data

## Features Included

### 1. Database Setup

- PostgreSQL database `"Inventory_DB"`
- `users` table with role-based access
- `categories` table for product grouping
- `products` table for inventory items
- `stock_logs` table for audit history

### 2. Database Connection

- PDO-based PostgreSQL connection
- Exception-based error handling
- Prepared statements for database safety
- Reusable connection pattern through configuration

### 3. User Authentication

- Prepared statements to reduce SQL injection risk
- Password hashing with `password_hash()`
- Password verification with `password_verify()`
- Secure session handling
- Session ID regeneration after login

### 4. Application UI

- Login page
- Dashboard
- Protected routes
- Logout flow

## Additional Security Best Practices

### Password Security

- Use unique, randomly generated administrator passwords
- Rotate credentials if they were ever committed to git
- Prefer a password manager over shared text files
- Revoke and replace any exposed database passwords immediately

### Template Config Files

Recommended committed files:

- `.env.example`
- `config/database.php.example`
- `config/app.php.example`

Recommended runtime-only files:

- `.env`
- `config/database.php`
- `config/*.local.php`

### Production Deployment Checklist

Before deploying:

1. Confirm no secrets remain in `README.md`, config files, or git history.
2. Use environment variables or a secret manager for all credentials.
3. Disable debug mode in production.
4. Verify PostgreSQL uses a least-privilege application user.
5. Enforce HTTPS at the web server or proxy.
6. Store sessions securely and set secure cookie flags.
7. Rotate any credentials that were previously exposed.
8. Review file permissions on config, logs, and upload directories.
9. Back up the database securely and test restore procedures.
10. Confirm error logs do not expose credentials or stack traces publicly.

## File Descriptions

| File or Directory | Description |
|------|-------------|
| `sql/` | PostgreSQL schema, migration, or seed files |
| `config/` | Application configuration and templates |
| `includes/` | Shared helpers such as auth, validation, and sessions |
| `modules/` | Feature-specific application modules |
| `login.php` | Login form and authentication entry point |
| `logout.php` | Session termination and redirect logic |
| `run_setup.php` | One-time Render database installer; delete after it shows PASS |

## Troubleshooting

### Database Connection Failed

1. Make sure PostgreSQL is running.
2. Verify the database name is exactly `"Inventory_DB"` when created.
3. Confirm `pdo_pgsql` is enabled in PHP.
4. Check that `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` are correct.

### Invalid Username or Password

1. Confirm the admin user was created successfully.
2. Verify the stored password was hashed with PHP's `password_hash()`.
3. Check that the account is active and has the expected role.

### Schema Import Errors

1. Make sure you are using PostgreSQL-compatible SQL.
2. Replace any MySQL-specific syntax such as `AUTO_INCREMENT`.
3. Re-run migrations or schema setup against a clean database if needed.

### Blank Page or PHP Error

1. Check PHP and web server error logs.
2. Verify required PHP extensions are installed.
3. Make sure your configuration file or environment variables are loaded correctly.

## Support

When validating your setup, confirm:

1. PostgreSQL is running and reachable.
2. The application can read its environment variables or local config file.
3. The schema was imported without errors.
4. The first admin user can authenticate successfully.

## Testing the Setup

After setup, verify:

1. The login page loads.
2. The PostgreSQL connection succeeds.
3. You can sign in with the admin account you created.
4. Protected pages require authentication.
5. Logout returns you to the login page.

---

**Created for**: Inventory System using PHP and PostgreSQL
**Last Updated**: 2026-03-07
