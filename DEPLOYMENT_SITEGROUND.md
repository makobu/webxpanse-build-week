# SiteGround Deployment Guide - crm.makdennis.dev

## SiteGround Directory Structure

SiteGround uses `public_html/` as the web root directory. Files outside `public_html/` are not web-accessible.

## Deployment Options

### Option 1: Install in public_html (Recommended for Subdomain)

If `crm.makdennis.dev` is a subdomain pointing to its own directory:

1. **Upload files** to: `/home/username/public_html/` (or subdomain directory)
2. **Web root** will be: `/home/username/public_html/public/`
3. **Configure subdomain** in SiteGround cPanel to point to `public_html/public/`

### Option 2: Install Outside public_html (Recommended for Main Domain)

If you want to keep CRM files separate:

1. **Upload files** to: `/home/username/crm/` (outside public_html)
2. **Create symlink** or configure subdomain to point to `/home/username/crm/public/`
3. **Or** configure subdomain document root to `/home/username/crm/public/`

### Option 3: Install Directly in public_html/public

1. **Upload CRM files** to: `/home/username/public_html/crm/`
2. **Access via:** `https://crm.makdennis.dev/crm/public/`
3. **Or** configure subdomain to point to `public_html/crm/public/`

## Recommended Setup (Option 2)

### Step 1: Upload Files

Upload all CRM files to `/home/username/crm/` (outside public_html):

```
/home/username/
├── crm/                    ← Upload here
│   ├── api/
│   ├── public/             ← This will be web root
│   ├── config/
│   ├── .env
│   └── ...
└── public_html/            ← Your main site
    └── ...
```

### Step 2: Configure Subdomain in SiteGround

1. Go to **cPanel → Subdomains**
2. Find or create `crm.makdennis.dev`
3. Set **Document Root** to: `/home/username/crm/public`
4. Click **Create** or **Update**

### Step 3: Install Dependencies

Via SSH or cPanel Terminal:
```bash
cd /home/username/crm
composer install --no-dev --optimize-autoloader
```

### Step 4: Create .env File

```bash
cd /home/username/crm
cp env.production.template .env
```

Edit `.env` and verify:
- `APP_URL=https://webxpanse.com`
- Database credentials are correct
- SMTP settings are configured

### Step 5: Set Permissions

```bash
cd /home/username/crm
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod 775 uploads cache logs
chmod 600 .env
```

### Step 6: Run Migrations

```bash
cd /home/username/crm
php database/migrations/migrate.php
```

### Step 7: Create Admin User

```bash
php scripts/create_admin_user.php
```

## Fixing 403 Error on SiteGround

### Common Issues:

1. **Subdomain Document Root Not Set**
   - Go to cPanel → Subdomains
   - Ensure `crm.makdennis.dev` points to `/home/username/crm/public`

2. **Missing .htaccess**
   - Ensure `public/.htaccess` exists
   - Upload `public/.htaccess` file

3. **File Permissions**
   ```bash
   chmod 755 -R /home/username/crm
   chmod 644 /home/username/crm/public/.htaccess
   ```

4. **PHP Version**
   - SiteGround cPanel → PHP Version
   - Ensure PHP 8.1+ is selected

5. **mod_rewrite Enabled**
   - SiteGround usually has this enabled by default
   - Check in cPanel → Apache Modules

## SiteGround-Specific .htaccess

The `public/.htaccess` file should work, but if you need SiteGround-specific settings:

```apache
# SiteGround Optimized .htaccess
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /
    
    # Redirect to HTTPS
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
    
    # Front controller
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>

# Security
Options -Indexes
<Files ".env">
    Require all denied
</Files>
```

## Testing

1. Visit: `https://crm.makdennis.dev`
2. Should redirect to: `https://crm.makdennis.dev/login.php`
3. Login with admin credentials

## Database Connection

If database host is not `localhost`, check SiteGround cPanel → MySQL Databases for the correct host (might be `localhost` or an IP address).

## Troubleshooting

### Still Getting 403?

1. **Check subdomain configuration:**
   - cPanel → Subdomains → `crm.makdennis.dev`
   - Document Root should be: `/home/username/crm/public`

2. **Test direct file access:**
   - `https://crm.makdennis.dev/index.php`
   - `https://crm.makdennis.dev/login.php`

3. **Check error logs:**
   - cPanel → Error Log
   - Look for specific 403 error details

4. **Verify file exists:**
   - cPanel → File Manager
   - Navigate to `crm/public/index.php`
   - Ensure file exists and has correct permissions

5. **Test PHP:**
   - Create `public/test.php` with: `<?php phpinfo(); ?>`
   - Visit: `https://crm.makdennis.dev/test.php`
   - If this works, PHP is fine, issue is with routing

## Quick Checklist

- [ ] Files uploaded to correct location
- [ ] Subdomain configured in cPanel
- [ ] Document Root points to `crm/public/`
- [ ] `.htaccess` file exists in `public/` directory
- [ ] File permissions set correctly (755/644)
- [ ] `.env` file created with correct settings
- [ ] Composer dependencies installed
- [ ] Database migrations run
- [ ] Admin user created
- [ ] PHP 8.1+ selected in cPanel
