# Troubleshooting 500 Internal Server Error

## Quick Diagnosis

### 1. Check System Health
Visit: `https://your-app.onrender.com/health.php`

This will show:
- ✅ PHP version
- ✅ Database connection status
- ✅ Required extensions (pdo, pdo_pgsql, zip)
- ✅ File system permissions (logs, uploads directories)

### 2. Review Error Logs
Connect to container and check:
```
/var/www/html/logs/php_errors.log
/var/www/html/logs/app.log
```

In Render dashboard:
- Click "Logs" tab
- Look for PHP errors and stack traces

## Common Causes and Solutions

### ❌ "Database Connection Failed"
**Cause:** PostgreSQL service not linked or not running

**Solution:**
1. Go to Render dashboard
2. Check PostgreSQL service status (should be green)
3. In web service, go to "Databases" tab
4. Click "Connect Database" and select your PostgreSQL service
5. Redeploy the web service

### ❌ "SQLSTATE[3D000]: Invalid catalog name"
**Cause:** Database doesn't exist or tables not created

**Solution:**
1. Ensure PostgreSQL service is running
2. Redeploy web service to trigger database link
3. Visit `https://your-app.onrender.com/run_setup.php`
4. Confirm all tables are created successfully

### ❌ "Connection refused" or "Network error"
**Cause:** Database service not accessible from web service

**Solution:**
1. Check that PostgreSQL service is linked to web service
2. Verify `DATABASE_URL` environment variable is set:
   - Go to Environment tab
   - Should look like: `postgresql://user:pass@render.com:5432/dbname`
3. Restart both services

### ❌ "Access denied for user"
**Cause:** Database credentials incorrect

**Solution:**
1. Environment variables should be auto-populated by Render
2. If manually set, ensure they match PostgreSQL service
3. Check PostgreSQL service details for correct credentials

### ❌ "Logs directory not writable"
**Cause:** Permission issue in container

**Solution:**
1. Redeploy the service (Dockerfile rebuilds with correct permissions)
2. Or manually fix: `chmod 755 /var/www/html/logs`

### ❌ Redirect Loop (Too Many Redirects)
**Cause:** HTTPS redirect misconfiguration

**Solution:**
1. The .htaccess has been updated to handle Render proxies
2. Redeploy web service to get latest .htaccess
3. Clear browser cache
4. If still happening, disable HTTP→HTTPS redirect in .htaccess temporarily

## Deployment Checklist

- [ ] PostgreSQL service created and running (green status)
- [ ] PostgreSQL service linked to web service
- [ ] `DATABASE_URL` environment variable visible
- [ ] Web service deployed successfully
- [ ] No errors in Render logs (check Logs tab)
- [ ] Health check passes: `/health.php` returns `"status": "ok"`
- [ ] Database setup complete: `/run_setup.php` shows all tables created
- [ ] Can login to admin panel
- [ ] Can view inventory/dashboard

## Testing the Fix

### Local Test (Before Deploying)
```bash
# Set environment variable
export DATABASE_URL="postgresql://user:pass@localhost:5432/test_db"

# Run health check
curl http://localhost:8000/health.php

# Should return JSON with status "ok" or "degraded"
```

### Remote Test (After Deploying to Render)
```bash
# Check health
curl https://your-app.onrender.com/health.php

# Check if database is connected
curl https://your-app.onrender.com/health.php | grep "connected"

# Should show: "connected": true
```

## Emergency Recovery

If the app is completely broken:

1. **Check database service:**
   - Visit Render dashboard
   - Is PostgreSQL service running? (Should be green)
   - Are credentials correct?

2. **Force rebuild:**
   - In Render web service, click "Manual Deploy"
   - Select latest commit
   - Click "Deploy"
   - Wait for build and startup to complete

3. **Check logs in real-time:**
   - Render dashboard → Logs tab
   - Scroll to latest entries
   - Look for error messages
   - Note the error and search in DEPLOYMENT.md

4. **Reinitialize database:**
   - Ensure PostgreSQL is running
   - Visit `/run_setup.php`
   - Confirm all tables created
   - If errors, note them and troubleshoot

## Getting Help

1. **Document the error:**
   - Visit `/health.php` and copy the JSON response
   - Check logs and copy error messages
   - Note the time it occurred

2. **Check DEPLOYMENT.md:**
   - Comprehensive deployment guide
   - Includes environment variables needed
   - Step-by-step deployment instructions

3. **Review logs:**
   - PHP errors: `logs/php_errors.log`
   - Application logs: `logs/app.log`
   - Render container logs: Logs tab in dashboard

## What Was Fixed in This Version

1. **Global Error Handler** - Prevents blank 500 errors
2. **Database Connection Check** - Shows helpful message if DB unavailable
3. **Health Check Endpoint** - `/health.php` for monitoring
4. **Better HTTPS Handling** - Works with Render's proxy
5. **Improved Logging** - All errors logged for debugging
6. **Updated Dockerfile** - Better PHP configuration for production

All these fixes ensure that even if something fails, you get a useful error message instead of a blank 500 error page.
