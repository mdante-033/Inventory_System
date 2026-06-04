# Render Deployment Guide

## Fix for 500 Internal Server Error

### What Was Fixed
This deployment includes fixes for the 500 Internal Server Error that was occurring on Render:

1. **Global Error Handler** (`error_handler.php`)
   - Catches all PHP errors and exceptions
   - Prevents blank 500 error pages
   - Logs errors to `logs/php_errors.log`
   - Displays user-friendly error messages

2. **Database Connection Check** (Updated `index.php`)
   - Checks if database is available before rendering the page
   - Shows a helpful error page if database connection fails
   - Prevents fatal errors from being exposed

3. **Health Check Endpoint** (`health.php`)
   - Available at `/health.php`
   - Returns JSON with system status
   - Useful for monitoring and debugging deployment issues
   - Can be used by Render to check app health

4. **Improved HTTPS Handling** (Updated `.htaccess`)
   - Now respects `X-Forwarded-Proto` header from Render proxy
   - Prevents redirect loops on Render
   - Works with both direct connections and proxied connections

5. **Better Logging** (Updated `Dockerfile`)
   - PHP error logging properly configured
   - Errors are logged to `logs/php_errors.log`
   - Memory limit increased to 256MB

## How to Deploy on Render

### Step 1: Create PostgreSQL Database Service on Render
1. Go to https://dashboard.render.com
2. Click "New +"
3. Select "PostgreSQL"
4. Name it: `inventory-db` (or your preferred name)
5. Set a password (Render will auto-generate one)
6. Click "Create Database"
7. **Wait for it to be available** (green status)

### Step 2: Create Web Service
1. Click "New +"
2. Select "Web Service"
3. Connect your GitHub repository
4. Fill in the configuration:
   - **Name:** `inventory-system`
   - **Environment:** `Docker`
   - **Branch:** `main` (or your deployment branch)
   - **Dockerfile Path:** `./Dockerfile`

### Step 3: Add Environment Variables
In the web service settings, go to "Environment" and click "Add from .env.example" or manually add:

```
DATABASE_URL=<automatically filled when you link the database>
DB_HOST=<automatically filled>
DB_PORT=5432
DB_NAME=Inventory_DB
DB_USER=<automatically filled>
DB_PASSWORD=<automatically filled>
APP_URL=https://your-service-name.onrender.com
APP_ENV=production
APP_DEBUG=false
SMTP_HOST=smtp-relay.brevo.com
SMTP_PORT=587
SMTP_FROM_NAME=Inventory System
SMTP_FROM_ADDR=noreply@yourdomain.com
SMTP_USERNAME=<your-email@brevo.com>
SMTP_PASSWORD=<your-brevo-api-key>
```

### Step 4: Link Database Service
1. In your web service settings, go to "Databases"
2. Click "Connect Database"
3. Select the PostgreSQL service you created (`inventory-db`)
4. The `DATABASE_URL` environment variable will be automatically populated

### Step 5: Deploy and Initialize
1. Click "Deploy"
2. Wait for deployment to complete
3. Once deployed, run database setup:
   - Visit `https://your-service-name.onrender.com/run_setup.php`
   - This will create all necessary tables

### Step 6: Verify Deployment
Check the health endpoint to verify everything is working:
```
https://your-service-name.onrender.com/health.php
```

Expected response:
```json
{
  "status": "ok",
  "database": {
    "connected": true,
    "server_time": "2026-06-04 20:15:29"
  },
  "services": {
    "pdo": "available",
    "pdo_pgsql": "available",
    "zip": "available",
    "uploads": "writable",
    "logs": "writable"
  }
}
```

## Troubleshooting

### Still Getting 500 Error?
1. **Check the logs:**
   - In Render dashboard, go to Logs tab
   - Look for PHP errors

2. **Check health status:**
   - Visit `/health.php` endpoint
   - See which service is failing

3. **Verify environment variables:**
   - Go to Environment tab in web service
   - Ensure `DATABASE_URL` is set and accessible

4. **Check database service:**
   - Ensure PostgreSQL service is running (green status)
   - Check if database credentials are correct

### Database Connection Failed
1. Verify PostgreSQL service is linked to web service
2. Check that `DATABASE_URL` environment variable is set
3. Ensure database user has permissions to create tables
4. Run setup script: `/run_setup.php`

### File Permission Issues
The `logs` and `uploads` directories must be writable:
- The Dockerfile automatically sets permissions
- If you see "not_writable" in `/health.php`, the container may need to restart

## Security Notes
- `APP_DEBUG=false` in production - errors won't be displayed to users
- `error_handler.php` logs all errors securely
- Database credentials are in environment variables (never in code)
- `.htaccess` protects sensitive files and directories

## Local Development
To test locally before deploying:

1. Create a `.env.local` file:
```
DATABASE_URL=postgres://user:password@localhost:5432/inventory_db
APP_ENV=local
APP_DEBUG=true
```

2. Run PHP built-in server:
```bash
php -S localhost:8000
```

3. Visit `http://localhost:8000`

## Support
- Check logs: `logs/app.log` and `logs/php_errors.log`
- Test health: `https://your-app.onrender.com/health.php`
- Run setup if needed: `/run_setup.php`
