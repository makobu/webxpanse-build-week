# SiteGround Deployment Steps - crm.makdennis.dev

## Quick Deployment Guide

### Step 1: Upload Files
Upload ALL CRM files to: `/home/yourusername/public_html/crm/`

**Structure:**
```
public_html/crm/
├── .htaccess          ← Root .htaccess (redirects to public/)
├── api/
├── public/            ← Web root (masked from URL)
│   ├── .htaccess
│   ├── index.php
│   └── ...
├── config/
├── core/
├── modules/
├── services/
├── vendor/
├── .env (create this)
└── ... (all other files)
```

### Step 2: Install Dependencies
Via SSH or cPanel Terminal:
```bash
cd /home/yourusername/public_html/crm
composer install --no-dev --optimize-autoloader
```

### Step 3: Create .env File
```bash
cd /home/yourusername/public_html/crm
cp env.production.template .env
```

Edit `.env` and set:
- `APP_URL=https://webxpanse.com`
- Database credentials (from cPanel → MySQL Databases)
- SMTP settings

### Step 4: Set File Permissions
```bash
cd /home/yourusername/public_html/crm
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod 775 uploads cache logs
chmod 600 .env
```

### Step 5: Run Database Migrations
```bash
cd /home/yourusername/public_html/crm
php database/migrations/migrate.php
```

### Step 6: Create Admin User
```bash
php scripts/create_admin_user.php
```

### Step 7: Configure PHP Version
1. Go to cPanel → Select PHP Version
2. Choose PHP 8.1 or higher
3. Click Save

### Step 8: Enable SSL
1. Go to cPanel → SSL/TLS Status
2. Enable SSL for `crm.makdennis.dev`
3. SiteGround provides free SSL certificates

## How It Works

The `.htaccess` file in `public_html/crm/` uses SiteGround's recommended method to:
- Redirect all requests to the `public/` folder
- Mask `/public/` from URLs (visitors see `https://crm.makdennis.dev` not `https://crm.makdennis.dev/public/`)
- Allow direct access to `/api/` endpoints
- Block access to sensitive directories

## Testing

1. Visit: `https://crm.makdennis.dev`
   - Should show login page
   - URL should NOT show `/public/`

2. Test API: `https://crm.makdennis.dev/api/test_smtp.php`
   - Should work directly

3. Test direct file: `https://crm.makdennis.dev/dashboard.php`
   - Should redirect to `public/dashboard.php` and work

## Troubleshooting

### 403 Forbidden Error
- Check that `.htaccess` file exists in `public_html/crm/`
- Verify file permissions (755 for directories, 644 for files)
- Check cPanel Error Log for specific errors

### 500 Internal Server Error
- Check PHP version (must be 8.1+)
- Check `.env` file exists and has correct settings
- Check error logs in cPanel

### Path Not Found
- Verify `.htaccess` redirect is working
- Check that `public/.htaccess` exists
- Test direct file access: `https://crm.makdennis.dev/index.php`

## Important Notes

- The `/public/` folder is masked from URLs
- All existing `/crm/public/` and `/crm/api/` paths in code will work
- Sensitive files (`.env`, `config/`, etc.) are protected
- API endpoints are accessible directly at `/api/`
