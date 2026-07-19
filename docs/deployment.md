# Deployment Guide

Complete guide for deploying the Self-Hosted CRM System to production.

For the current live-server upload package, environment requirements, migration order, strict preflight gates, and post-upload smoke test, use [Live Upload Checklist](live-upload-checklist.md) as the release runbook.

## Table of Contents

1. [Pre-Deployment Checklist](#pre-deployment-checklist)
2. [Server Requirements](#server-requirements)
3. [Installation](#installation)
4. [Configuration](#configuration)
5. [Database Setup](#database-setup)
6. [Worker Processes](#worker-processes)
7. [Web Server Configuration](#web-server-configuration)
8. [SSL/TLS Setup](#ssltls-setup)
9. [Backup and Recovery](#backup-and-recovery)
10. [Monitoring](#monitoring)
11. [Scaling](#scaling)
12. [Troubleshooting](#troubleshooting)

---

## Pre-Deployment Checklist

- [ ] Server meets requirements
- [ ] Domain name configured
- [ ] SSL certificate obtained
- [ ] Database server ready
- [ ] SMTP credentials available
- [ ] FCM service account credentials available for mobile push
- [ ] Backup strategy planned
- [ ] Monitoring tools configured
- [ ] Security measures in place
- [ ] Live upload file list reviewed in `docs/live-upload-checklist.md`
- [ ] Live `.env` prepared from `env.production.template`
- [ ] Fresh database and uploads backups exist before migrations
- [ ] Strict production gates pass before opening traffic
- [ ] Post-upload health and browser smoke tests complete

---

## Server Requirements

### Minimum Requirements

- **CPU**: 2 cores
- **RAM**: 4GB
- **Storage**: 20GB SSD
- **OS**: Linux (Ubuntu 20.04+ recommended) or Windows Server

### Recommended Requirements

- **CPU**: 4+ cores
- **RAM**: 8GB+
- **Storage**: 50GB+ SSD
- **OS**: Linux (Ubuntu 22.04 LTS)

### Software Requirements

- **PHP**: 8.1 or higher
- **MySQL**: 8.0 or higher
- **Web Server**: Apache 2.4+ or Nginx 1.18+
- **Composer**: Latest version
- **Redis**: 6.0+ (optional but recommended)

---

## Installation

### Step 1: Clone Repository

```bash
cd /var/www
git clone <repository-url> crm
cd crm
```

### Step 2: Install Dependencies

```bash
composer install --no-dev --optimize-autoloader
composer run runtime:prepare
```

### Step 3: Set Permissions

```bash
chown -R www-data:www-data /var/www/crm
find /var/www/crm -type d -exec chmod 755 {} \;
find /var/www/crm -type f -exec chmod 644 {} \;
chmod 775 /var/www/crm/cache /var/www/crm/logs /var/www/crm/tmp /var/www/crm/uploads
find /var/www/crm/cache /var/www/crm/logs /var/www/crm/tmp /var/www/crm/uploads -type d -exec chmod 775 {} \;
chmod 600 /var/www/crm/.env
```

### Step 4: Configure Environment

```bash
cp env.production.template .env
nano .env
```

Configure all required environment variables (see Configuration section and `docs/live-upload-checklist.md`). Never upload a filled `.env` from local development.

---

## Configuration

### Environment Variables

Edit `.env` file with production values:

```env
# Application
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

# Database
DB_HOST=localhost
DB_NAME=crm
DB_USER=crm_user
DB_PASS=secure_password

# Email (SMTP)
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USER=your-email@example.com
SMTP_PASS=your-password
SMTP_ENCRYPTION=tls
SMTP_FROM_EMAIL=noreply@your-domain.com
SMTP_FROM_NAME=Your Company

# Personal Assistant SMTP (Optional) - Leave empty to use main SMTP
# Use a separate account for assistant replies and daily digests
# EMAIL_ASSISTANT_SMTP_HOST=
# EMAIL_ASSISTANT_SMTP_PORT=587
# EMAIL_ASSISTANT_SMTP_USER=
# EMAIL_ASSISTANT_SMTP_PASS=
# EMAIL_ASSISTANT_SMTP_ENCRYPTION=tls
# EMAIL_ASSISTANT_FROM_EMAIL=
# EMAIL_ASSISTANT_FROM_NAME=Personal Assistant

# WhatsApp (Optional)
WHATSAPP_API_URL=https://api.whatsapp.com
WHATSAPP_API_TOKEN=your-token
WHATSAPP_WEBHOOK_URL=https://your-domain.com/crm/api/webhooks/whatsapp.php

# AI Services (Optional)
AI_SERVICE_URL=https://api.openai.com
AI_API_KEY=your-api-key
AI_MODEL=gpt-3.5-turbo

# Firebase Cloud Messaging (required for mobile push)
# Set either FCM_SERVICE_ACCOUNT_JSON or FCM_SERVICE_ACCOUNT_PATH
FCM_PROJECT_ID=your-firebase-project-id
FCM_SERVICE_ACCOUNT_JSON=
FCM_SERVICE_ACCOUNT_PATH=/secure/path/to/firebase-service-account.json

# Cache backend (Optional but recommended)
# Use auto to try Redis, then Memcached, then file cache.
# On SiteGround Memcached, use CACHE_DRIVER=memcached.
CACHE_DRIVER=auto

# Redis (Optional)
REDIS_HOST=localhost
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DATABASE=0

# Memcached (Optional)
MEMCACHED_HOST=127.0.0.1
MEMCACHED_PORT=11211

# Security
SESSION_LIFETIME=7200
CSRF_TOKEN_LIFETIME=3600
```

### Security Settings

1. **Disable Debug Mode**:
   ```
   APP_DEBUG=false
   ```

2. **Use Strong Passwords** for database and services

3. **Restrict File Permissions**:
   ```bash
   chmod 600 .env
   ```

4. **Hide Sensitive Files**:
   - Ensure `.env` is not web-accessible
   - Block access to `vendor/`, `config/`, etc.

### Personal Assistant SMTP (Optional)

The Personal Assistant (email replies, daily digests) can use a separate SMTP account from the main CRM. This prevents assistant emails from being sent through the main system and avoids accidental use of the wrong account.

**Security:** The main CRM email (`SMTP_FROM_EMAIL`) cannot receive Personal Assistant instructions. Only `EMAIL_ASSISTANT_SYSTEM_EMAIL` (when explicitly set to a different address) receives instructions. This prevents the main transactional email from being instructed by mistake.

- **Leave all `EMAIL_ASSISTANT_*` vars empty** to use the main SMTP settings (default, backward compatible).
- **`EMAIL_ASSISTANT_SYSTEM_EMAIL`** must be a different address from `SMTP_FROM_EMAIL` when the assistant is enabled.
- **Configure assistant SMTP** in Settings → Personal Assistant → "Personal Assistant SMTP (optional)" or via `.env`:
  - `EMAIL_ASSISTANT_SMTP_HOST`, `EMAIL_ASSISTANT_SMTP_PORT`, `EMAIL_ASSISTANT_SMTP_USER`, `EMAIL_ASSISTANT_SMTP_PASS`, `EMAIL_ASSISTANT_SMTP_ENCRYPTION`
  - `EMAIL_ASSISTANT_FROM_EMAIL`, `EMAIL_ASSISTANT_FROM_NAME`
- **`EMAIL_ASSISTANT_ALLOWED_SENDERS`** - Comma-separated whitelist of emails that can send instructions; leave empty to allow all admin users.

### Mobile Push (Firebase Cloud Messaging)

The CRM already exposes mobile push registration and FCM delivery for the Flutter app. Production mobile push requires:

- `FCM_PROJECT_ID`
- either `FCM_SERVICE_ACCOUNT_JSON` or `FCM_SERVICE_ACCOUNT_PATH`

Store the service account JSON outside the web root when using `FCM_SERVICE_ACCOUNT_PATH`, and make sure the web/PHP user can read it. Do not commit service account JSON into the repository.

### Mobile Freshness Nudges

The Flutter app can call lightweight mobile sync endpoints to improve perceived freshness when a user opens or resumes the app. These calls are best-effort hints only. They do not replace cron jobs or worker ownership.

Available mobile sync endpoints:

- `POST /api/mobile/sync/pulse.php`
- `POST /api/mobile/sync/notifications.php`
- `POST /api/mobile/sync/reminders.php`
- `POST /api/mobile/sync/inbox.php`

Expected behavior:

- endpoints must stay user-scoped, fast, and idempotent
- deeper work should be queued or skipped behind cooldowns
- server-side workers remain authoritative for email, workflows, inbox fetch, scheduled reports, AI autoresponders, WhatsApp, and SMS
- Redis is recommended so cooldowns and warmed mobile caches persist between requests

---

## Database Setup

### Step 1: Create Database

```sql
CREATE DATABASE crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'crm_user'@'localhost' IDENTIFIED BY 'secure_password';
GRANT ALL PRIVILEGES ON crm.* TO 'crm_user'@'localhost';
FLUSH PRIVILEGES;
```

### Step 2: Run Migrations

```bash
php database/migrations/migrate.php
```

### Step 3: Create Admin User

```bash
php scripts/create_admin_user.php
```

Or use the web interface if available.

### Step 4: Optimize Database

```sql
-- Add indexes for performance
-- (Most indexes are created by migrations)

-- Optimize tables
OPTIMIZE TABLE contacts;
OPTIMIZE TABLE activities;
OPTIMIZE TABLE emails;
```

---

## Worker Processes

For a complete list of all cron jobs and background workers, see **[Cron Jobs Setup](CRON_SETUP.md)**.

### Using Supervisor (Recommended)

Install Supervisor:
```bash
sudo apt-get install supervisor
```

Create configuration file `/etc/supervisor/conf.d/crm-workers.conf`:

```ini
[program:crm-email-worker]
command=php /var/www/crm/cli/email_worker.php
directory=/var/www/crm
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/crm/email-worker.log

[program:crm-whatsapp-worker]
command=php /var/www/crm/cli/whatsapp_worker.php
directory=/var/www/crm
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/crm/whatsapp-worker.log

[program:crm-scheduled-email-worker]
command=php /var/www/crm/cli/scheduled_email_worker.php
directory=/var/www/crm
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/crm/scheduled-email-worker.log

[program:crm-scheduled-report-worker]
command=php /var/www/crm/cli/scheduled_report_worker.php
directory=/var/www/crm
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/crm/scheduled-report-worker.log
```

Start workers:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start crm-*
```

### Using systemd (Alternative)

Create service file `/etc/systemd/system/crm-email-worker.service`:

```ini
[Unit]
Description=CRM Email Worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/crm
ExecStart=/usr/bin/php /var/www/crm/cli/email_worker.php
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Enable and start:
```bash
sudo systemctl enable crm-email-worker
sudo systemctl start crm-email-worker
```

---

## Web Server Configuration

### Apache Configuration

Create virtual host `/etc/apache2/sites-available/crm.conf`:

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    ServerAlias www.your-domain.com
    
    DocumentRoot /var/www/crm/public
    
    <Directory /var/www/crm/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    # Block access to sensitive directories
    <DirectoryMatch "^/var/www/crm/(vendor|config|database|tests)">
        Require all denied
    </DirectoryMatch>
    
    # Logging
    ErrorLog ${APACHE_LOG_DIR}/crm-error.log
    CustomLog ${APACHE_LOG_DIR}/crm-access.log combined
</VirtualHost>
```

Enable site:
```bash
sudo a2ensite crm.conf
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### Nginx Configuration

Create configuration `/etc/nginx/sites-available/crm`:

```nginx
server {
    listen 80;
    server_name your-domain.com www.your-domain.com;
    root /var/www/crm/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Block access to sensitive directories
    location ~ ^/(vendor|config|database|tests) {
        deny all;
    }

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
}
```

Enable site:
```bash
sudo ln -s /etc/nginx/sites-available/crm /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## SSL/TLS Setup

### Using Let's Encrypt (Free)

Install Certbot:
```bash
sudo apt-get install certbot python3-certbot-apache
# or for Nginx:
sudo apt-get install certbot python3-certbot-nginx
```

Obtain certificate:
```bash
sudo certbot --apache -d your-domain.com -d www.your-domain.com
# or for Nginx:
sudo certbot --nginx -d your-domain.com -d www.your-domain.com
```

Auto-renewal is set up automatically.

### Manual SSL Certificate

1. Obtain SSL certificate from your provider
2. Install certificate files
3. Update web server configuration to use HTTPS
4. Redirect HTTP to HTTPS

---

## Backup and Recovery

### Automated Backups

Create backup script `/usr/local/bin/crm-backup.sh`:

```bash
#!/bin/bash
BACKUP_DIR="/backups/crm"
DATE=$(date +%Y%m%d_%H%M%S)

# Database backup
mysqldump -u crm_user -p'password' crm > "$BACKUP_DIR/db_$DATE.sql"

# Files backup
tar -czf "$BACKUP_DIR/files_$DATE.tar.gz" /var/www/crm/uploads

# Keep only last 30 days
find $BACKUP_DIR -name "*.sql" -mtime +30 -delete
find $BACKUP_DIR -name "*.tar.gz" -mtime +30 -delete
```

Add to crontab:
```bash
0 2 * * * /usr/local/bin/crm-backup.sh
```

### Manual Backup

**Database:**
```bash
mysqldump -u crm_user -p crm > backup.sql
```

**Files:**
```bash
tar -czf backup-files.tar.gz /var/www/crm/uploads
```

### Recovery

**Database:**
```bash
mysql -u crm_user -p crm < backup.sql
```

**Files:**
```bash
tar -xzf backup-files.tar.gz -C /
```

---

## Monitoring

### Health Check Endpoint

Monitor: `GET /api/health.php`

Returns system health status.

### Log Monitoring

Monitor log files:
- Application logs: `/var/log/crm/`
- Web server logs: `/var/log/apache2/` or `/var/log/nginx/`
- Worker logs: Supervisor/systemd logs

### Performance Monitoring

1. **Database Monitoring**:
   - Monitor slow queries
   - Check connection pool
   - Monitor table sizes

2. **Server Monitoring**:
   - CPU usage
   - Memory usage
   - Disk usage
   - Network traffic

3. **Application Monitoring**:
   - Response times
   - Error rates
   - Queue depths

---

## Scaling

### Horizontal Scaling

1. **Load Balancer**: Use Nginx or HAProxy
2. **Multiple App Servers**: Deploy to multiple servers
3. **Database Replication**: Master-slave setup
4. **Session Storage**: Use Redis for shared sessions

### Vertical Scaling

1. **Increase Server Resources**: More CPU, RAM
2. **Database Optimization**: Better hardware, SSD
3. **Caching**: Redis for caching
4. **CDN**: For static assets

### Database Scaling

1. **Read Replicas**: For read-heavy workloads
2. **Partitioning**: For large tables
3. **Indexing**: Optimize queries
4. **Connection Pooling**: Manage connections efficiently

---

## Troubleshooting

### Common Issues

**Issue**: Workers not processing jobs
- Check worker processes are running
- Check queue tables in database
- Review worker logs

**Issue**: Emails not sending
- Verify SMTP settings
- Check email worker is running
- Review email queue
- Test SMTP connection

**Issue**: Slow performance
- Enable Redis caching
- Check database indexes
- Review slow query log
- Optimize queries

**Issue**: Database connection errors
- Verify database credentials
- Check database server is running
- Review connection limits
- Check firewall rules

### Debug Mode

Enable in `.env`:
```
APP_DEBUG=true
```

**Warning**: Only enable in development!
For production, keep `APP_DEBUG=false` and do not set the legacy `DEBUG` flag.

### Log Locations

- Application: `/var/log/crm/`
- Apache: `/var/log/apache2/`
- Nginx: `/var/log/nginx/`
- PHP: Check `php.ini` error_log setting
- MySQL: `/var/log/mysql/error.log`

---

## Maintenance

### Regular Tasks

1. **Daily**:
   - Monitor system health
   - Check error logs
   - Review backup status

2. **Weekly**:
   - Review performance metrics
   - Check disk space
   - Review security logs

3. **Monthly**:
   - Update dependencies: `composer update`
   - Review and optimize database
   - Update documentation

### Updates

1. **Backup** before updating
2. **Pull latest code**: `git pull`
3. **Update dependencies**: `composer install`
4. **Run migrations**: `php database/migrations/migrate.php`
5. **Clear cache**: Delete cache files
6. **Test** thoroughly
7. **Monitor** for issues

---

## Security Checklist

- [ ] SSL/TLS enabled
- [ ] Debug mode disabled
- [ ] Strong passwords used
- [ ] File permissions set correctly
- [ ] Sensitive files not web-accessible
- [ ] Firewall configured
- [ ] Regular security updates
- [ ] Audit logging enabled
- [ ] CSRF protection enabled
- [ ] SQL injection prevention verified
- [ ] XSS protection verified

---

## Post-Deployment

1. **Verify Installation**:
   - Access web interface
   - Test login
   - Create test contact
   - Send test email

2. **Monitor**:
   - Check health endpoint
   - Review logs
   - Monitor performance

3. **Documentation**:
   - Document server configuration
   - Record credentials securely
   - Document backup procedures

---

*Last Updated: 2026-01-24*
