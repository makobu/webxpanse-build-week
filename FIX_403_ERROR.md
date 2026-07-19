# Fixing 403 Forbidden Error

## Common Causes of 403 Errors

### 1. Missing .htaccess File
The `.htaccess` file in the `public/` directory is required for Apache.

**Solution:** Ensure `public/.htaccess` exists (already created in the project)

### 2. Web Server Not Pointing to public/ Directory
The web server must point to the `public/` directory, not the root.

**Solution:** Update your web server configuration:

**Apache:**
```apache
<VirtualHost *:80>
    ServerName crm.makdennis.dev
    DocumentRoot /path/to/crm/public
    
    <Directory /path/to/crm/public>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>
</VirtualHost>
```

**Nginx:**
```nginx
server {
    listen 80;
    server_name crm.makdennis.dev;
    root /path/to/crm/public;
    index index.php;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
}
```

### 3. File Permissions
Files need proper permissions for the web server to read them.

**Solution:**
```bash
chmod 755 -R /path/to/crm
chmod 775 -R /path/to/crm/public
chmod 644 /path/to/crm/public/.htaccess
chown -R www-data:www-data /path/to/crm
```

### 4. Apache mod_rewrite Not Enabled
Apache needs mod_rewrite enabled for .htaccess to work.

**Solution:**
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### 5. AllowOverride Not Set
Apache needs `AllowOverride All` in the Directory block.

**Solution:** See Apache configuration above.

### 6. Directory Listing Disabled
If trying to access a directory without index file, you'll get 403.

**Solution:** Ensure `index.php` exists in `public/` directory (it does).

## Quick Fix Checklist

- [ ] `.htaccess` file exists in `public/` directory
- [ ] Web server DocumentRoot points to `public/` directory
- [ ] File permissions are correct (755 for directories, 644 for files)
- [ ] Apache mod_rewrite is enabled
- [ ] AllowOverride All is set in Apache config
- [ ] Web server user (www-data) owns the files

## Test Steps

1. Check if `public/index.php` is accessible:
   ```
   https://crm.makdennis.dev/index.php
   ```

2. Check if `public/login.php` is accessible:
   ```
   https://crm.makdennis.dev/login.php
   ```

3. Check Apache error logs:
   ```bash
   tail -f /var/log/apache2/error.log
   ```

4. Check file permissions:
   ```bash
   ls -la /path/to/crm/public/
   ```

## If Still Getting 403

1. **Check Apache error logs** for specific error messages
2. **Verify web server user** can read files
3. **Test with simple PHP file** - create `public/test.php` with `<?php phpinfo(); ?>`
4. **Check SELinux** (if enabled): `setenforce 0` to test
