# Deployment Guide

## Production Deployment Checklist

### Pre-Deployment

- [ ] All tests passing
- [ ] Code review completed
- [ ] Security audit passed
- [ ] Performance testing completed
- [ ] Documentation updated
- [ ] Backup strategy in place

### Backend Deployment

#### 1. Server Requirements

- PHP 8.0+
- MySQL 8.0+
- Composer
- Nginx or Apache
- SSL Certificate
- Redis (optional, for caching)

#### 2. Environment Configuration

Set production environment variables:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://hr.yourcompany.com

DB_HOST=prod-db-host
DB_PORT=3306
DB_NAME=hrdemo
DB_USER=hr_user
DB_PASS=secure_password

JWT_SECRET=production-secret-key-minimum-32-characters
SESSION_LIFETIME=120
SESSION_SAME_SITE=Strict
```

#### 3. Install Dependencies

```bash
cd backend
composer install --no-dev --optimize-autoloader --no-scripts
```

#### 4. Database Migration

```bash
cd backend/database
php run_migration.php
```

#### 5. Set Permissions

```bash
chmod -R 775 storage
chmod -R 775 public/uploads
```

#### 6. Web Server Configuration

**Nginx Configuration:**

```nginx
server {
    listen 80;
    server_name hr.yourcompany.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl;
    server_name hr.yourcompany.com;

    ssl_certificate /path/to/certificate.crt;
    ssl_certificate_key /path/to/private.key;

    root /var/www/hrdemo/backend/public;
    index index.php;

    client_max_body_size 50M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

#### 7. Configure Cron Jobs

```bash
# Add to crontab (crontab -e)
0 8 * * * /usr/bin/php /var/www/hrdemo/cron/check_notifications.php
0 0 * * * /usr/bin/php /var/www/hrdemo/cron/daily_reports.php
```

### Frontend Deployment

#### 1. Build for Production

```bash
cd frontend
npm install
npm run build
```

#### 2. Deploy Build Files

Copy the `dist/` folder to your web server:

```bash
scp -r frontend/dist/* user@server:/var/www/hr.yourcompany.com/
```

#### 3. Configure Web Server

**Nginx Configuration:**

```nginx
server {
    listen 443 ssl;
    server_name hr.yourcompany.com;

    ssl_certificate /path/to/certificate.crt;
    ssl_certificate_key /path/to/private.key;

    root /var/www/hr.yourcompany.com;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location /api {
        proxy_pass http://localhost:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
}
```

### SSL Configuration

1. Obtain SSL certificate from Let's Encrypt or CA
2. Configure web server for HTTPS
3. Enable HSTS header
4. Disable SSLv3 and TLS 1.0/1.1
5. Enable TLS 1.2 and 1.3 only

### Security Hardening

#### Backend

- [ ] Disable `APP_DEBUG` in production
- [ ] Set secure session cookie parameters
- [ ] Enable rate limiting
- [ ] Configure CORS properly
- [ ] Set up Web Application Firewall (WAF)
- [ ] Regular security updates
- [ ] Database user with minimal privileges
- [ ] Hide sensitive files from web access

#### Frontend

- [ ] Enable Content Security Policy
- [ ] Disable XSS protection headers
- [ ] Use Subresource Integrity for CDN resources
- [ ] Minimize bundle size
- [ ] Enable gzip/brotli compression

### Monitoring

#### Application Monitoring

- Error tracking (Sentry, Bugsnag)
- Performance monitoring (New Relic, Datadog)
- Uptime monitoring (UptimeRobot, Pingdom)
- Log aggregation (ELK Stack, Splunk)

#### Database Monitoring

- Query performance
- Connection pool usage
- Slow query log
- Replication status

### Backup Strategy

#### Database Backups

```bash
# Daily backup script
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M%S)
mysqldump -u root -p hrdemo > /backups/hrdemo_$DATE.sql
gzip /backups/hrdemo_$DATE.sql

# Keep last 30 days
find /backups -name "hrdemo_*.sql.gz" -mtime +30 -delete
```

#### File Backups

- Uploaded files (daily)
- Application code (on deploy)
- Configuration files (version controlled)

### Scaling

#### Horizontal Scaling

1. **Load Balancer**: Use Nginx or HAProxy
2. **Multiple App Servers**: Deploy backend on multiple servers
3. **Database Replication**: Master-slave setup
4. **Redis Cache**: Shared session storage and caching
5. **CDN**: For frontend static assets

#### Vertical Scaling

- Increase server resources (CPU, RAM)
- Optimize database queries
- Implement caching layers
- Use queue workers for background jobs

### Rollback Plan

1. Keep previous version in `/releases/` folder
2. Database migrations should be reversible
3. Quick rollback script:

```bash
#!/bin/bash
# rollback.sh
ln -nfs /var/www/hrdemo/releases/previous /var/www/hrdemo/current
php /var/www/hrdemo/current/backend/artisan migrate:rollback
systemctl reload php-fpm
```

### Health Checks

#### Backend Health Check

```bash
curl -f http://localhost:8000/api/health || exit 1
```

#### Frontend Health Check

```bash
curl -f https://hr.yourcompany.com/ || exit 1
```

### Performance Optimization

#### Backend

- Enable OPcache
- Use PHP-FPM
- Database query optimization
- Response caching
- Gzip compression

#### Frontend

- Code splitting
- Tree shaking
- Image optimization
- Lazy loading
- Service worker for offline support

### Troubleshooting

#### Common Issues

**502 Bad Gateway**
- Check PHP-FPM is running
- Check Nginx configuration
- Check error logs

**500 Internal Server Error**
- Check `storage/logs/error.log`
- Verify database connection
- Check file permissions

**Slow Performance**
- Enable caching
- Optimize database queries
- Use CDN for static assets
- Enable compression

### Maintenance

#### Regular Tasks

- Daily: Database backups
- Weekly: Security updates
- Monthly: Performance review
- Quarterly: Security audit

#### Updates

1. Test in staging environment
2. Backup production database
3. Deploy during low-traffic hours
4. Monitor for errors
5. Have rollback plan ready