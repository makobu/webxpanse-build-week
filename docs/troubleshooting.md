# Troubleshooting Guide

Common issues and solutions for the Self-Hosted CRM System.

## Table of Contents

1. [Installation Issues](#installation-issues)
2. [Database Issues](#database-issues)
3. [Email Issues](#email-issues)
4. [Performance Issues](#performance-issues)
5. [Authentication Issues](#authentication-issues)
6. [API Issues](#api-issues)
7. [Worker Issues](#worker-issues)
8. [General Issues](#general-issues)

---

## Installation Issues

### Issue: Composer dependencies won't install

**Symptoms**: `composer install` fails

**Solutions**:
1. Check PHP version: `php -v` (must be 8.1+)
2. Check Composer version: `composer --version`
3. Clear Composer cache: `composer clear-cache`
4. Update Composer: `composer self-update`
5. Check memory limit: Increase `memory_limit` in `php.ini`

### Issue: Migrations fail

**Symptoms**: Migration errors, tables not created

**Solutions**:
1. Check database connection in `.env`
2. Verify database user has CREATE privileges
3. Check MySQL version (must be 8.0+)
4. Review migration error messages
5. Manually run failed migrations if needed

### Issue: Permission errors

**Symptoms**: "Permission denied" errors

**Solutions**:
```bash
# Set correct ownership
chown -R www-data:www-data /var/www/crm

# Set correct permissions
chmod -R 755 /var/www/crm
chmod -R 775 /var/www/crm/uploads
chmod -R 775 /var/www/crm/cache
```

---

## Database Issues

### Issue: "Table doesn't exist" errors

**Symptoms**: Errors about missing tables

**Solutions**:
1. Check if migrations ran: `SELECT * FROM migrations;`
2. Run migrations: `php database/migrations/migrate.php`
3. Check database name in `.env` matches actual database
4. Verify database user has access to the database

### Issue: Connection refused

**Symptoms**: "Connection refused" or "Access denied"

**Solutions**:
1. Verify database server is running: `systemctl status mysql`
2. Check database credentials in `.env`
3. Verify database user exists and has permissions
4. Check firewall rules
5. Test connection: `mysql -u user -p -h host database`

### Issue: Slow queries

**Symptoms**: Pages load slowly, database queries take long

**Solutions**:
1. Check for missing indexes
2. Review slow query log
3. Optimize queries
4. Add indexes for frequently queried columns
5. Consider database optimization

### Issue: Foreign key constraint errors

**Symptoms**: "Cannot add foreign key constraint"

**Solutions**:
1. Ensure referenced tables exist
2. Check data types match
3. Verify indexes exist on foreign key columns
4. Check table engine (must be InnoDB)

---

## Email Issues

### Issue: Emails not sending

**Symptoms**: Emails stuck in queue, not delivered

**Solutions**:
1. **Check SMTP settings** in Settings → Email
2. **Verify email worker is running**:
   ```bash
   ps aux | grep email_worker
   ```
3. **Check email queue**:
   ```sql
   SELECT * FROM email_queue WHERE status = 'pending';
   ```
4. **Test SMTP connection** manually
5. **Check email worker logs**
6. **Verify SMTP credentials** are correct
7. **Check firewall** allows SMTP port (usually 587)

### Issue: Emails going to spam

**Symptoms**: Emails delivered but marked as spam

**Solutions**:
1. Set up SPF record for your domain
2. Set up DKIM signing
3. Set up DMARC policy
4. Use reputable SMTP service
5. Avoid spam trigger words
6. Include unsubscribe link

### Issue: Email tracking not working

**Symptoms**: Opens/clicks not recorded

**Solutions**:
1. Verify tracking pixel is included in emails
2. Check email tracking table exists
3. Verify tracking URLs are correct
4. Check if email client blocks images (common)

---

## Performance Issues

### Issue: Slow page loads

**Symptoms**: Pages take long to load

**Solutions**:
1. **Enable Redis caching**:
   - Install Redis
   - Configure in `.env`
   - Restart application

2. **Optimize database**:
   ```sql
   OPTIMIZE TABLE contacts;
   OPTIMIZE TABLE activities;
   ```

3. **Check server resources**:
   - CPU usage
   - Memory usage
   - Disk I/O

4. **Review slow queries**:
   ```sql
   SHOW FULL PROCESSLIST;
   ```

5. **Add pagination** to large lists
6. **Use filters** to reduce data

### Issue: High memory usage

**Symptoms**: Out of memory errors

**Solutions**:
1. Increase PHP memory limit in `php.ini`:
   ```
   memory_limit = 256M
   ```

2. Optimize queries to fetch less data
3. Use pagination
4. Clear cache regularly
5. Review and optimize code

### Issue: Database connection pool exhausted

**Symptoms**: "Too many connections" errors

**Solutions**:
1. Increase MySQL max_connections
2. Close unused connections
3. Use connection pooling
4. Review connection usage
5. Optimize long-running queries

---

## Authentication Issues

### Issue: Can't log in

**Symptoms**: Login fails, wrong credentials

**Solutions**:
1. Verify username/email is correct
2. Check password (case-sensitive)
3. Verify user account is active
4. Clear browser cookies
5. Check session configuration
6. Verify database connection

### Issue: Session expires too quickly

**Symptoms**: Logged out frequently

**Solutions**:
1. Increase session lifetime in `.env`:
   ```
   SESSION_LIFETIME=7200
   ```

2. Check PHP session configuration
3. Verify session storage is working
4. Check if cookies are being cleared

### Issue: CSRF token errors

**Symptoms**: "Invalid CSRF token" errors

**Solutions**:
1. Ensure session is active
2. Check CSRF token is included in forms
3. Verify token hasn't expired
4. Clear browser cache
5. Check if multiple tabs are interfering

---

## API Issues

### Issue: API returns 401 Unauthorized

**Symptoms**: API calls fail with 401

**Solutions**:
1. Verify authentication is working
2. Check session cookie is included
3. Verify user is logged in
4. Check API endpoint authentication requirements

### Issue: API returns 403 Forbidden

**Symptoms**: API calls fail with 403

**Solutions**:
1. Check CSRF token is included
2. Verify CSRF token is valid
3. Check user permissions
4. Verify request method is correct

### Issue: API returns 500 errors

**Symptoms**: Internal server errors

**Solutions**:
1. Check server error logs
2. Enable debug mode (development only)
3. Review API endpoint code
4. Check database connection
5. Verify all dependencies are installed

---

## Worker Issues

### Issue: Workers not processing jobs

**Symptoms**: Queue items stuck, not processed

**Solutions**:
1. **Check workers are running**:
   ```bash
   supervisorctl status
   # or
   systemctl status crm-email-worker
   ```

2. **Restart workers**:
   ```bash
   supervisorctl restart crm-*
   ```

3. **Check worker logs** for errors
4. **Verify queue tables** exist
5. **Check PHP CLI** is working: `php -v`
6. **Verify file permissions** on worker scripts

### Issue: Workers crash frequently

**Symptoms**: Workers stop unexpectedly

**Solutions**:
1. Check worker logs for errors
2. Increase PHP memory limit
3. Review worker code for bugs
4. Check system resources
5. Verify database connection stability

---

## General Issues

### Issue: "Class not found" errors

**Symptoms**: Fatal error about missing classes

**Solutions**:
1. Run `composer dump-autoload`
2. Verify class namespace is correct
3. Check file location matches namespace
4. Verify Composer autoload is included

### Issue: File upload fails

**Symptoms**: Can't upload files

**Solutions**:
1. Check file size limits in `php.ini`:
   ```
   upload_max_filesize = 10M
   post_max_size = 10M
   ```

2. Verify upload directory exists and is writable
3. Check file permissions
4. Verify file type is allowed
5. Check disk space

### Issue: Images not displaying

**Symptoms**: Broken image links

**Solutions**:
1. Verify file paths are correct
2. Check file permissions
3. Verify web server can access files
4. Check URL rewriting is working
5. Verify file exists on disk

### Issue: JavaScript not working

**Symptoms**: Interactive features don't work

**Solutions**:
1. Check browser console for errors
2. Verify JavaScript files are loaded
3. Check for JavaScript errors
4. Clear browser cache
5. Verify jQuery/other dependencies are loaded

### Issue: Styling looks broken

**Symptoms**: CSS not applied correctly

**Solutions**:
1. Clear browser cache
2. Verify CSS files are loading
3. Check file paths are correct
4. Verify web server is serving CSS files
5. Check for CSS syntax errors

---

## Debug Mode

### Enable Debug Mode

Set in `.env`:
```
APP_DEBUG=true
```

**Warning**: Only enable in development! Never in production!
Leave the legacy `DEBUG` flag unset on production hosts.

### View Debug Information

- Check browser console for JavaScript errors
- Review PHP error logs
- Check web server error logs
- Enable PHP error display (development only)

### Log Locations

- **Application logs**: `/var/log/crm/` (if configured)
- **PHP errors**: Check `php.ini` `error_log` setting
- **Apache**: `/var/log/apache2/error.log`
- **Nginx**: `/var/log/nginx/error.log`
- **MySQL**: `/var/log/mysql/error.log`
- **Worker logs**: Supervisor/systemd logs

---

## Getting Help

### Before Asking for Help

1. Check this troubleshooting guide
2. Review error logs
3. Search documentation
4. Check FAQ
5. Enable debug mode (development)

### Information to Provide

When reporting issues, include:
- Error message (exact text)
- Steps to reproduce
- PHP version
- MySQL version
- Server environment
- Relevant log entries
- Screenshots (if applicable)

---

## Common Error Messages

### "PDOException: SQLSTATE[42S02]: Base table or view not found"

**Solution**: Run migrations: `php database/migrations/migrate.php`

### "Fatal error: Uncaught Error: Class 'CRM\...' not found"

**Solution**: Run `composer dump-autoload`

### "Warning: session_start(): Failed to initialize storage module"

**Solution**: Check session directory permissions and configuration

### "Allowed memory size exhausted"

**Solution**: Increase `memory_limit` in `php.ini`

### "Connection refused"

**Solution**: Check database server is running and credentials are correct

---

*Last Updated: 2026-01-24*
