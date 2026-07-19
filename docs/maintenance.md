# Maintenance Procedures

Regular maintenance tasks and procedures for the WebXpanse business operating platform.

## Table of Contents

1. [Daily Tasks](#daily-tasks)
2. [Weekly Tasks](#weekly-tasks)
3. [Monthly Tasks](#monthly-tasks)
4. [Database Maintenance](#database-maintenance)
5. [Cache Management](#cache-management)
6. [Log Management](#log-management)
7. [Update Procedures](#update-procedures)
8. [Backup Procedures](#backup-procedures)

---

## Daily Tasks

### System Health Check

1. **Check health endpoint**:
   ```bash
   curl https://your-domain.com/crm/api/health.php
   ```

2. **Review error logs**:
   - Application errors
   - PHP errors
   - Web server errors

3. **Monitor worker processes**:
   ```bash
   supervisorctl status
   # or
   systemctl status crm-*
   ```

4. **Check disk space**:
   ```bash
   df -h
   ```

5. **Review queue status**:
   ```sql
   SELECT status, COUNT(*) FROM email_queue GROUP BY status;
   ```

### Quick Checks

- [ ] System is accessible
- [ ] No critical errors in logs
- [ ] Workers are running
- [ ] Disk space is adequate
- [ ] Email queue is processing

---

## Weekly Tasks

### Performance Review

1. **Check slow queries**:
   ```sql
   SELECT * FROM mysql.slow_log ORDER BY start_time DESC LIMIT 10;
   ```

2. **Review performance metrics**:
   - Page load times
   - API response times
   - Database query times

3. **Check cache hit rates** (if using Redis):
   ```bash
   redis-cli INFO stats
   ```

### Security Review

1. **Review audit logs**:
   ```sql
   SELECT * FROM audit_log 
   WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
   ORDER BY created_at DESC;
   ```

2. **Check for failed login attempts**:
   ```sql
   SELECT * FROM audit_log 
   WHERE action = 'login_failed'
   AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY);
   ```

3. **Review user activity**:
   - Active users
   - Unusual patterns
   - Permission changes

### Data Quality

1. **Check for duplicate contacts**:
   ```sql
   SELECT email, COUNT(*) as count 
   FROM contacts 
   GROUP BY email 
   HAVING count > 1;
   ```

2. **Review orphaned records**:
   - Contacts without activities
   - Tasks without assignments
   - Emails without contacts

---

## Monthly Tasks

### Database Optimization

1. **Optimize tables**:
   ```sql
   OPTIMIZE TABLE contacts;
   OPTIMIZE TABLE activities;
   OPTIMIZE TABLE emails;
   OPTIMIZE TABLE tasks;
   OPTIMIZE TABLE events;
   ```

2. **Analyze tables**:
   ```sql
   ANALYZE TABLE contacts;
   ANALYZE TABLE activities;
   ```

3. **Check table sizes**:
   ```sql
   SELECT 
       table_name,
       ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
   FROM information_schema.TABLES
   WHERE table_schema = 'crm'
   ORDER BY size_mb DESC;
   ```

### Dependency Updates

1. **Update Composer dependencies**:
   ```bash
   composer update --no-dev
   ```

2. **Review changelogs** for breaking changes
3. **Test updates** in development first
4. **Update production** after testing

### Documentation Review

1. **Update documentation** if needed
2. **Review and update FAQ**
3. **Check documentation accuracy**
4. **Add new procedures** if discovered

### Performance Analysis

1. **Review query performance**:
   - Identify slow queries
   - Add indexes if needed
   - Optimize queries

2. **Check server resources**:
   - CPU usage trends
   - Memory usage trends
   - Disk I/O patterns

3. **Review caching effectiveness**:
   - Cache hit rates
   - Cache size
   - Cache eviction patterns

---

## Database Maintenance

### Regular Optimization

**Weekly**:
```sql
-- Optimize frequently used tables
OPTIMIZE TABLE contacts;
OPTIMIZE TABLE activities;
```

**Monthly**:
```sql
-- Optimize all tables
OPTIMIZE TABLE contacts, activities, emails, tasks, events, deals, notes, documents;
```

### Index Maintenance

1. **Check for missing indexes**:
   ```sql
   -- Find tables without indexes on foreign keys
   SELECT * FROM information_schema.STATISTICS 
   WHERE table_schema = 'crm' 
   AND index_name = 'PRIMARY';
   ```

2. **Rebuild indexes** if needed:
   ```sql
   ALTER TABLE contacts ENGINE=InnoDB;
   ```

### Data Cleanup

1. **Archive old data** (if needed):
   ```sql
   -- Example: Archive activities older than 2 years
   CREATE TABLE activities_archive LIKE activities;
   INSERT INTO activities_archive 
   SELECT * FROM activities 
   WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 YEAR);
   DELETE FROM activities 
   WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 YEAR);
   ```

2. **Clean up orphaned records**:
   ```sql
   -- Example: Remove activities for deleted contacts
   DELETE FROM activities 
   WHERE contact_id NOT IN (SELECT id FROM contacts);
   ```

### Backup Verification

1. **Test backup restoration** monthly
2. **Verify backup integrity**
3. **Check backup retention** policy
4. **Document restoration** procedures

---

## Cache Management

### Redis Cache

**Clear cache**:
```bash
redis-cli FLUSHALL
```

**Check cache status**:
```bash
redis-cli INFO
```

**Monitor cache**:
```bash
redis-cli MONITOR
```

### Application Cache

**Clear application cache**:
```bash
rm -rf cache/*
```

**Or via code**:
```php
use CRM\CacheManager;
CacheManager::clear();
```

### Cache Optimization

1. **Review cache keys** and TTLs
2. **Monitor cache hit rates**
3. **Adjust cache strategies** as needed
4. **Clear stale cache** regularly

---

## Log Management

### Log Rotation

**Set up log rotation** in `/etc/logrotate.d/crm`:

```
/var/log/crm/*.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 0644 www-data www-data
    sharedscripts
    postrotate
        supervisorctl restart crm-* > /dev/null 2>&1 || true
    endscript
}
```

### Log Review

1. **Application logs**: Review daily
2. **Error logs**: Review and address issues
3. **Access logs**: Review for suspicious activity
4. **Worker logs**: Check for processing issues

### Log Cleanup

1. **Archive old logs** (older than 90 days)
2. **Compress logs** to save space
3. **Delete very old logs** (per retention policy)

---

## Update Procedures

### Pre-Update Checklist

- [ ] Backup database
- [ ] Backup files
- [ ] Review changelog
- [ ] Test in development
- [ ] Schedule maintenance window
- [ ] Notify users

### Update Steps

1. **Stop workers**:
   ```bash
   supervisorctl stop crm-*
   ```

2. **Backup**:
   ```bash
   ./scripts/backup.sh
   ```

3. **Update code**:
   ```bash
   git pull origin main
   ```

4. **Update dependencies**:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

5. **Run migrations**:
   ```bash
   php database/migrations/migrate.php
   ```

6. **Clear cache**:
   ```bash
   redis-cli FLUSHALL
   rm -rf cache/*
   ```

7. **Restart services**:
   ```bash
   supervisorctl start crm-*
   systemctl restart php-fpm
   systemctl restart nginx
   ```

8. **Verify**:
   - Check health endpoint
   - Test critical features
   - Review logs

### Rollback Procedure

If update fails:

1. **Restore code**:
   ```bash
   git checkout <previous-version>
   ```

2. **Restore database**:
   ```bash
   mysql -u user -p crm < backup.sql
   ```

3. **Restart services**

---

## Backup Procedures

### Automated Backups

**Daily backup script** (`/usr/local/bin/crm-backup.sh`):

```bash
#!/bin/bash
BACKUP_DIR="/backups/crm/$(date +%Y%m%d)"
mkdir -p $BACKUP_DIR

# Database backup
mysqldump -u $DB_USER -p$DB_PASS $DB_NAME > "$BACKUP_DIR/db.sql"

# Files backup
tar -czf "$BACKUP_DIR/files.tar.gz" /var/www/crm/uploads

# Keep only last 30 days
find /backups/crm -type d -mtime +30 -exec rm -rf {} \;
```

**Add to crontab**:
```bash
0 2 * * * /usr/local/bin/crm-backup.sh
```

### Backup Verification

1. **Test restoration** monthly
2. **Verify backup integrity**
3. **Check backup sizes**
4. **Document restoration** process

### Backup Retention

- **Daily backups**: Keep 7 days
- **Weekly backups**: Keep 4 weeks
- **Monthly backups**: Keep 12 months

---

## Performance Tuning

### Database Tuning

1. **Review slow query log**
2. **Add indexes** for slow queries
3. **Optimize queries**
4. **Adjust MySQL configuration** if needed

### PHP Tuning

1. **Adjust memory limit**:
   ```
   memory_limit = 256M
   ```

2. **Optimize opcache**:
   ```
   opcache.enable=1
   opcache.memory_consumption=128
   ```

3. **Adjust max execution time**:
   ```
   max_execution_time = 300
   ```

### Web Server Tuning

**Apache**:
- Adjust `MaxRequestWorkers`
- Enable compression
- Configure caching headers

**Nginx**:
- Adjust worker processes
- Enable gzip compression
- Configure caching

---

## Security Maintenance

### Regular Security Tasks

1. **Review audit logs** weekly
2. **Check for security updates** monthly
3. **Review user permissions** quarterly
4. **Security audit** annually

### Security Updates

1. **Update PHP** when security patches available
2. **Update dependencies** regularly
3. **Review security advisories**
4. **Apply patches** promptly

---

## Monitoring

### Health Monitoring

- **Uptime monitoring**: Use external service
- **Health endpoint**: `/api/health.php`
- **Error rate monitoring**: Track error logs
- **Performance monitoring**: Track response times

### Alerting

Set up alerts for:
- High error rates
- Slow response times
- Disk space low
- Workers not running
- Database connection issues

---

## Emergency Procedures

### System Down

1. **Check health endpoint**
2. **Review error logs**
3. **Check server status**
4. **Check database status**
5. **Restart services** if needed
6. **Restore from backup** if necessary

### Data Loss

1. **Stop all operations**
2. **Assess damage**
3. **Restore from backup**
4. **Verify data integrity**
5. **Resume operations**
6. **Document incident**

### Security Breach

1. **Isolate system** if possible
2. **Assess breach scope**
3. **Change all passwords**
4. **Review audit logs**
5. **Fix vulnerabilities**
6. **Notify stakeholders**
7. **Document incident**

---

## Maintenance Schedule

### Daily (5 minutes)
- Health check
- Error log review
- Worker status check

### Weekly (30 minutes)
- Performance review
- Security review
- Queue status check

### Monthly (2 hours)
- Database optimization
- Dependency updates
- Documentation review
- Backup verification

### Quarterly (4 hours)
- Security audit
- Performance analysis
- Architecture review
- Capacity planning

---

*Last Updated: 2026-01-24*
