# Quick Deployment Guide - crm.makdennis.dev

## Production URL
**https://crm.makdennis.dev**

## Database Credentials (Already Configured)
- **Database:** create this in your hosting control panel
- **User:** create this in your hosting control panel
- **Password:** generate a unique password in your hosting control panel
- **Host:** localhost (update if different)

## Quick Steps

### 1. Upload Files
Upload all project files to your server (excluding `.env`, `vendor/`, `.git/`, `tests/`)

### 2. On Server - Install Dependencies
```bash
cd /path/to/crm
composer install --no-dev --optimize-autoloader
```

### 3. Create .env File
```bash
# Copy template
cp env.production.template .env

# The .env file already has:
# - Database credentials configured
# - APP_URL=https://crm.makdennis.dev
# - SMTP settings (update password if needed)
```

### 4. Set Permissions
```bash
chmod 755 -R .
chmod 775 -R uploads cache logs
chmod 600 .env
```

### 5. Run Migrations
```bash
php database/migrations/migrate.php
```

### 6. Create Admin User
```bash
php scripts/create_admin_user.php
```

### 7. Configure Web Server
Point your web server to the `public/` directory.

**Apache Example:**
```apache
<VirtualHost *:80>
    ServerName crm.makdennis.dev
    DocumentRoot /path/to/crm/public
    
    <Directory /path/to/crm/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

**Nginx Example:**
```nginx
server {
    listen 80;
    server_name crm.makdennis.dev;
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

### 8. Test
1. Visit: https://crm.makdennis.dev
2. Login with admin credentials
3. Test SMTP in Settings → Email

## Important Notes

- **APP_URL** is already set to `https://crm.makdennis.dev` in the template
- Database credentials are pre-configured
- Update SMTP password in `.env` with the value from your mail provider
- Ensure SSL certificate is installed for HTTPS
- Make sure database host is correct (might not be `localhost`)

## Post-Deployment

- [ ] Test login
- [ ] Test SMTP sending
- [ ] Verify database connection
- [ ] Check file permissions
- [ ] Set up workers (optional)
