# Quick Deployment Guide - Live Server

## Database Credentials
Use credentials generated in your hosting control panel. Do not commit live database names, users, or passwords to this repository.

## Step 1: Copy Files to Server

### Option A: Using FTP/SFTP
1. Connect to your server via FTP/SFTP client (FileZilla, WinSCP, etc.)
2. Upload all files EXCEPT:
   - `.env` (create new one on server)
   - `vendor/` (install via composer on server)
   - `.git/` (if exists)
   - `node_modules/` (if exists)
   - `tests/` (optional - not needed for production)
   - `.cursor/` (development files)

### Option B: Using Git (Recommended)
```bash
# On live server
cd /path/to/your/web/directory
git clone <your-repo-url> crm
cd crm
```

### Option C: Using ZIP Upload
1. Create a ZIP file of your project (excluding files above)
2. Upload ZIP to server
3. Extract on server

## Step 2: Install Dependencies

```bash
cd /path/to/crm
composer install --no-dev --optimize-autoloader
```

## Step 3: Create .env File

```bash
# Copy production template
cp .env.production .env

# Or create manually with these settings:
```

Edit `.env` file with your production settings:

```env
# Application
APP_ENV=production
APP_DEBUG=false
APP_URL=https://webxpanse.com

# Database (Live Server)
DB_HOST=localhost
DB_NAME=replace_with_database_name
DB_USER=replace_with_database_user
DB_PASS=replace_with_database_password

# Email (Update with your SMTP settings)
SMTP_HOST=mail.webxpanse.com
SMTP_PORT=465
SMTP_USER=info@webxpanse.com
SMTP_PASS=replace_with_smtp_password
SMTP_ENCRYPTION=ssl
SMTP_FROM_EMAIL=info@webxpanse.com
SMTP_FROM_NAME=Dennis

```

## Step 4: Set Permissions

```bash
# Set proper permissions
chmod 755 -R /path/to/crm
chmod 775 -R /path/to/crm/uploads
chmod 775 -R /path/to/crm/cache
chmod 600 /path/to/crm/.env

# Set ownership (adjust user/group for your server)
chown -R www-data:www-data /path/to/crm
```

## Step 5: Run Database Migrations

```bash
php database/migrations/migrate.php
```

## Step 6: Create Admin User

```bash
php scripts/create_admin_user.php
```

Or create via web interface after deployment.

## Step 7: Configure Web Server

### Apache (.htaccess should be in public/ directory)
Ensure your Apache virtual host points to the `public/` directory:

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /path/to/crm/public
    
    <Directory /path/to/crm/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Nginx
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/crm/public;
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
}
```

## Step 8: Test Installation

1. Visit: `https://your-domain.com/crm/public/` (or your configured path)
2. Login with admin credentials
3. Test SMTP settings
4. Verify database connection

## Step 9: Set Up Workers (Optional but Recommended)

For background email processing, set up workers:

```bash
# Using supervisor (Linux)
# Create /etc/supervisor/conf.d/crm-workers.conf
```

Or run manually:
```bash
php cli/email_worker.php
php cli/scheduled_email_worker.php
php cli/task_completion_worker.php
```

For hosts that only support URL-based cron jobs, configure a secret in `.env`:
```env
PROCESS_QUEUE_SECRET=replace-with-a-long-random-secret
```

Then call this URL every 5 minutes:
```text
https://your-domain.com/api/process_task_completion.php?token=YOUR_PROCESS_QUEUE_SECRET
```

## Files to Copy

### Required Files:
- All PHP files (`api/`, `cli/`, `config/`, `core/`, `modules/`, `public/`, `services/`, `views/`)
- `composer.json`, `composer.lock`
- `database/migrations/`
- `vendor/` (or install via composer on server)

### Files to Create on Server:
- `.env` (from `.env.production` template)

### Files to Exclude:
- `.env` (local development)
- `.git/`
- `tests/`
- `.cursor/`
- `node_modules/`
- `*.log` files

## Post-Deployment Checklist

- [ ] Database connection working
- [ ] Admin user created
- [ ] SMTP settings configured and tested
- [ ] File permissions set correctly
- [ ] Web server configured
- [ ] SSL certificate installed (HTTPS)
- [ ] Workers running (if needed)
- [ ] Backup strategy in place

## Troubleshooting

### Database Connection Issues
- Verify database credentials in `.env`
- Check database host (might be different from localhost)
- Ensure database user has proper permissions

### Permission Issues
- Check file ownership: `ls -la`
- Verify web server user can read files
- Check uploads/cache directories are writable

### SMTP Issues
- Test SMTP settings using Settings → Email → Test SMTP
- Verify SMTP credentials are correct
- Check firewall allows outbound SMTP connections

## Support

For issues, check:
- `docs/troubleshooting.md`
- `docs/deployment.md`
- Log files in `logs/` directory
