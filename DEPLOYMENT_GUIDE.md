# Budget Pro - Production Deployment Guide

**Version:** 2.0.0  
**Last Updated:** December 9, 2025

---

## 📋 Table of Contents

1. [Pre-Deployment Checklist](#pre-deployment-checklist)
2. [Server Requirements](#server-requirements)
3. [Deployment Methods](#deployment-methods)
4. [Environment Configuration](#environment-configuration)
5. [Security Hardening](#security-hardening)
6. [Performance Optimization](#performance-optimization)
7. [Monitoring & Logging](#monitoring--logging)
8. [Backup Strategy](#backup-strategy)
9. [SSL/TLS Configuration](#ssltls-configuration)
10. [Post-Deployment Verification](#post-deployment-verification)
11. [Troubleshooting](#troubleshooting)

---

## ✅ Pre-Deployment Checklist

### Code Preparation

- [ ] All tests passing (`php artisan test`)
- [ ] Code formatted with Pint (`./vendor/bin/pint`)
- [ ] `.env.example` updated with all required variables
- [ ] Database migrations tested on fresh database
- [ ] Seeders verified (optional demo data)
- [ ] Frontend assets built (`npm run build`)
- [ ] Git repository up to date

### Documentation

- [ ] README.md complete
- [ ] INSTALLATION_GUIDE.md reviewed
- [ ] CHANGELOG.md updated
- [ ] API documentation current
- [ ] User manual ready

### Security

- [ ] All secrets removed from code
- [ ] Debug mode disabled (`APP_DEBUG=false`)
- [ ] Strong `APP_KEY` generated
- [ ] Database credentials secure
- [ ] HTTPS/SSL configured
- [ ] CORS properly configured
- [ ] Rate limiting enabled

### Performance

- [ ] Query optimization complete
- [ ] Indexes added to database
- [ ] Caching strategy implemented
- [ ] Assets minified and compressed
- [ ] CDN configured (if applicable)

---

## 🖥️ Server Requirements

### Minimum Requirements

```
Server: VPS or Dedicated Server
OS: Ubuntu 20.04 LTS or higher (recommended)
PHP: 8.1+ (8.3+ recommended)
Database: MySQL 5.7+ or MariaDB 10.3+
Web Server: Apache 2.4+ or Nginx 1.18+
Memory: 2GB RAM minimum, 4GB+ recommended
Storage: 20GB minimum, SSD recommended
```

### Required PHP Extensions

```bash
# Check installed extensions
php -m

# Required extensions:
- php8.x-cli
- php8.x-fpm
- php8.x-mysql
- php8.x-pdo
- php8.x-mbstring
- php8.x-xml
- php8.x-bcmath
- php8.x-curl
- php8.x-gd
- php8.x-zip
- php8.x-tokenizer
- php8.x-json
```

### Install PHP 8.3 on Ubuntu

```bash
# Add PHP repository
sudo add-apt-repository ppa:ondrej/php
sudo apt update

# Install PHP 8.3 and extensions
sudo apt install php8.3 php8.3-fpm php8.3-mysql php8.3-mbstring \
    php8.3-xml php8.3-bcmath php8.3-curl php8.3-gd php8.3-zip \
    php8.3-tokenizer php8.3-json

# Verify installation
php -v
```

### Install MySQL

```bash
# Install MySQL 8.0
sudo apt install mysql-server

# Secure installation
sudo mysql_secure_installation

# Create database
sudo mysql -u root -p
mysql> CREATE DATABASE budget_pro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
mysql> CREATE USER 'budgetpro_user'@'localhost' IDENTIFIED BY 'strong_password_here';
mysql> GRANT ALL PRIVILEGES ON budget_pro.* TO 'budgetpro_user'@'localhost';
mysql> FLUSH PRIVILEGES;
mysql> EXIT;
```

### Install Composer

```bash
# Download and install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
sudo chmod +x /usr/local/bin/composer

# Verify
composer --version
```

### Install Node.js & NPM

```bash
# Install Node.js 18.x LTS
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install -y nodejs

# Verify
node -v
npm -v
```

---

## 🚀 Deployment Methods

### Method 1: Manual Deployment (FTP/SFTP)

**Step 1: Prepare Local Build**

```bash
# Build production assets
npm run build

# Install production dependencies only
composer install --no-dev --optimize-autoloader

# Clear development caches
php artisan config:clear
php artisan cache:clear
```

**Step 2: Upload Files**

```bash
# Upload via SFTP/FTP
# Upload ALL files EXCEPT:
# - node_modules/
# - .env
# - storage/logs/*
# - vendor/ (if installing on server)
```

**Step 3: Server Setup**

```bash
# SSH into server
ssh user@your-server.com

# Navigate to application
cd /var/www/html/budget-pro

# Set permissions
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# Install dependencies
composer install --no-dev --optimize-autoloader

# Configure environment
cp .env.example .env
nano .env  # Edit configuration

# Generate key
php artisan key:generate

# Run migrations
php artisan migrate --force

# Cache configuration
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Link storage
php artisan storage:link
```

---

### Method 2: Git Deployment

**Step 1: Server Initial Setup**

```bash
# Clone repository
cd /var/www/html
sudo git clone https://github.com/your-repo/budget-pro.git
cd budget-pro

# Checkout production branch
sudo git checkout production

# Set ownership
sudo chown -R www-data:www-data .

# Install dependencies
composer install --no-dev --optimize-autoloader
npm install --production
npm run build
```

**Step 2: Create Deployment Script**

```bash
# Create deploy.sh
sudo nano /var/www/html/budget-pro/deploy.sh
```

```bash
#!/bin/bash

# Budget Pro Deployment Script
APP_DIR="/var/www/html/budget-pro"

echo "🚀 Starting deployment..."

# Enter maintenance mode
cd $APP_DIR
php artisan down --message="Upgrading system" --retry=60

# Pull latest changes
git pull origin production

# Install/update dependencies
composer install --no-dev --optimize-autoloader --no-interaction
npm install --production
npm run build

# Run migrations
php artisan migrate --force

# Clear and rebuild cache
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize

# Exit maintenance mode
php artisan up

echo "✅ Deployment complete!"
```

**Make executable:**
```bash
sudo chmod +x deploy.sh
```

**Deploy:**
```bash
./deploy.sh
```

---

### Method 3: Docker Deployment

**docker-compose.yml:**

```yaml
version: '3.8'

services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: budget-pro-app
    ports:
      - "8000:8000"
    volumes:
      - ./storage:/var/www/html/storage
      - ./bootstrap/cache:/var/www/html/bootstrap/cache
    environment:
      - APP_ENV=production
      - APP_KEY=${APP_KEY}
      - DB_HOST=db
      - DB_DATABASE=${DB_DATABASE}
      - DB_USERNAME=${DB_USERNAME}
      - DB_PASSWORD=${DB_PASSWORD}
    depends_on:
      - db
      - redis

  db:
    image: mysql:8.0
    container_name: budget-pro-db
    ports:
      - "3306:3306"
    environment:
      - MYSQL_DATABASE=${DB_DATABASE}
      - MYSQL_USER=${DB_USERNAME}
      - MYSQL_PASSWORD=${DB_PASSWORD}
      - MYSQL_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
    volumes:
      - db_data:/var/lib/mysql

  redis:
    image: redis:alpine
    container_name: budget-pro-redis
    ports:
      - "6379:6379"

  nginx:
    image: nginx:alpine
    container_name: budget-pro-nginx
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./:/var/www/html
      - ./docker/nginx.conf:/etc/nginx/conf.d/default.conf
      - ./docker/ssl:/etc/nginx/ssl
    depends_on:
      - app

volumes:
  db_data:
```

**Dockerfile:**

```dockerfile
FROM php:8.3-fpm

# Install dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    nodejs \
    npm

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application
COPY . .

# Install dependencies
RUN composer install --no-dev --optimize-autoloader
RUN npm install --production && npm run build

# Set permissions
RUN chown -R www-data:www-data storage bootstrap/cache

# Expose port
EXPOSE 8000

CMD php artisan serve --host=0.0.0.0 --port=8000
```

**Deploy:**
```bash
docker-compose up -d
```

---

## ⚙️ Environment Configuration

### Production .env Template

```env
# Application
APP_NAME="Budget Pro"
APP_ENV=production
APP_KEY=base64:YOUR_GENERATED_KEY_HERE
APP_DEBUG=false
APP_URL=https://yourdomain.com

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=budget_pro
DB_USERNAME=budgetpro_user
DB_PASSWORD=your_secure_password_here

# Cache & Session
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# Redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Mail Configuration
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_username
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"

# Filesystem
FILESYSTEM_DISK=local

# Logging
LOG_CHANNEL=stack
LOG_LEVEL=error
LOG_DEPRECATIONS_CHANNEL=null

# Broadcast
BROADCAST_DRIVER=log

# Additional Security
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
```

### Generate Secure APP_KEY

```bash
php artisan key:generate --show
```

---

## 🔒 Security Hardening

### 1. File Permissions

```bash
# Set proper ownership
sudo chown -R www-data:www-data /var/www/html/budget-pro

# Set directory permissions
sudo find /var/www/html/budget-pro -type d -exec chmod 755 {} \;

# Set file permissions
sudo find /var/www/html/budget-pro -type f -exec chmod 644 {} \;

# Writable directories
sudo chmod -R 775 storage bootstrap/cache

# Protect sensitive files
sudo chmod 600 .env
```

### 2. Disable Directory Listing

**Apache (.htaccess):**
```apache
Options -Indexes
```

**Nginx:**
```nginx
autoindex off;
```

### 3. Hide Sensitive Files

**Apache:**
```apache
<FilesMatch "^\.">
    Require all denied
</FilesMatch>
```

**Nginx:**
```nginx
location ~ /\. {
    deny all;
}
```

### 4. Configure Firewall

```bash
# UFW (Ubuntu)
sudo ufw allow 22/tcp      # SSH
sudo ufw allow 80/tcp      # HTTP
sudo ufw allow 443/tcp     # HTTPS
sudo ufw enable

# Check status
sudo ufw status
```

### 5. Install Fail2Ban

```bash
# Install
sudo apt install fail2ban

# Configure
sudo cp /etc/fail2ban/jail.conf /etc/fail2ban/jail.local
sudo nano /etc/fail2ban/jail.local

# Enable and start
sudo systemctl enable fail2ban
sudo systemctl start fail2ban
```

### 6. Rate Limiting (Laravel)

```php
// app/Http/Kernel.php
protected $middlewareGroups = [
    'web' => [
        // ...
        \Illuminate\Routing\Middleware\ThrottleRequests::class.':60,1',
    ],
    'api' => [
        'throttle:api',
    ],
];
```

---

## ⚡ Performance Optimization

### 1. Enable OPcache

```bash
# Edit php.ini
sudo nano /etc/php/8.3/fpm/php.ini

# Add/uncomment:
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
opcache.fast_shutdown=1

# Restart PHP-FPM
sudo systemctl restart php8.3-fpm
```

### 2. Configure Redis Cache

```bash
# Install Redis
sudo apt install redis-server

# Configure
sudo nano /etc/redis/redis.conf
# Set: maxmemory 256mb
# Set: maxmemory-policy allkeys-lru

# Restart
sudo systemctl restart redis
```

**Laravel Configuration:**
```env
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

### 3. Database Optimization

```sql
-- Add missing indexes
CREATE INDEX idx_company_id ON stock_items(company_id);
CREATE INDEX idx_sale_date ON sale_records(sale_date);
CREATE INDEX idx_company_status ON stock_items(company_id, status);

-- Optimize tables
OPTIMIZE TABLE stock_items;
OPTIMIZE TABLE sale_records;
OPTIMIZE TABLE purchase_orders;

-- Analyze tables
ANALYZE TABLE stock_items;
ANALYZE TABLE sale_records;
```

### 4. Enable Gzip Compression

**Apache:**
```apache
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript
</IfModule>
```

**Nginx:**
```nginx
gzip on;
gzip_types text/plain text/css application/json application/javascript text/xml application/xml;
gzip_min_length 1000;
```

### 5. Laravel Caching

```bash
# Cache configuration
php artisan config:cache

# Cache routes
php artisan route:cache

# Cache views
php artisan view:cache

# Optimize autoloader
composer dump-autoload --optimize
```

---

## 📊 Monitoring & Logging

### 1. Application Logging

```php
// config/logging.php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['daily', 'slack'],
    ],
    'daily' => [
        'driver' => 'daily',
        'path' => storage_path('logs/laravel.log'),
        'level' => 'error',
        'days' => 14,
    ],
],
```

### 2. Setup Log Rotation

```bash
# Create logrotate config
sudo nano /etc/logrotate.d/budget-pro
```

```
/var/www/html/budget-pro/storage/logs/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
    sharedscripts
}
```

### 3. Monitor System Resources

```bash
# Install monitoring tools
sudo apt install htop iotop nethogs

# Check disk usage
df -h

# Check memory
free -m

# Check MySQL performance
mysqladmin -u root -p status
```

### 4. Application Monitoring (Optional)

Consider using services like:
- **Sentry** (Error tracking)
- **New Relic** (APM)
- **Datadog** (Infrastructure monitoring)
- **Laravel Telescope** (Local debugging)

---

## 💾 Backup Strategy

### 1. Database Backup Script

```bash
# Create backup script
sudo nano /usr/local/bin/backup-budget-pro.sh
```

```bash
#!/bin/bash

# Budget Pro Backup Script
BACKUP_DIR="/backups/budget-pro"
DB_NAME="budget_pro"
DB_USER="budgetpro_user"
DB_PASS="your_password"
DATE=$(date +%Y-%m-%d_%H-%M-%S)

# Create backup directory
mkdir -p $BACKUP_DIR

# Backup database
mysqldump -u$DB_USER -p$DB_PASS $DB_NAME | gzip > $BACKUP_DIR/db_$DATE.sql.gz

# Backup application files
tar -czf $BACKUP_DIR/files_$DATE.tar.gz /var/www/html/budget-pro/storage

# Backup environment file
cp /var/www/html/budget-pro/.env $BACKUP_DIR/.env_$DATE

# Remove backups older than 30 days
find $BACKUP_DIR -type f -mtime +30 -delete

echo "✅ Backup completed: $DATE"
```

```bash
# Make executable
sudo chmod +x /usr/local/bin/backup-budget-pro.sh

# Test backup
sudo /usr/local/bin/backup-budget-pro.sh
```

### 2. Automated Backups with Cron

```bash
# Edit crontab
sudo crontab -e

# Add daily backup at 2 AM
0 2 * * * /usr/local/bin/backup-budget-pro.sh >> /var/log/budget-pro-backup.log 2>&1
```

### 3. Offsite Backup to S3 (Optional)

```bash
# Install AWS CLI
sudo apt install awscli

# Configure
aws configure

# Sync backups to S3
aws s3 sync /backups/budget-pro s3://your-bucket/budget-pro-backups/
```

---

## 🔐 SSL/TLS Configuration

### Using Let's Encrypt (Free)

```bash
# Install Certbot
sudo apt install certbot python3-certbot-apache

# For Apache
sudo certbot --apache -d yourdomain.com -d www.yourdomain.com

# For Nginx
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d yourdomain.com -d www.yourdomain.com

# Auto-renewal
sudo certbot renew --dry-run
```

### Apache SSL VirtualHost

```apache
<VirtualHost *:443>
    ServerName yourdomain.com
    DocumentRoot /var/www/html/budget-pro/public

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/yourdomain.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/yourdomain.com/privkey.pem

    <Directory /var/www/html/budget-pro/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/budget-pro-error.log
    CustomLog ${APACHE_LOG_DIR}/budget-pro-access.log combined
</VirtualHost>
```

### Nginx SSL Configuration

```nginx
server {
    listen 443 ssl http2;
    server_name yourdomain.com;

    ssl_certificate /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    root /var/www/html/budget-pro/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

---

## ✓ Post-Deployment Verification

### 1. Functional Testing

```bash
# Check application status
curl -I https://yourdomain.com

# Test database connection
php artisan tinker
>>> DB::connection()->getPdo();
>>> exit

# Test queue worker
php artisan queue:work --once

# Check scheduled tasks
php artisan schedule:list
```

### 2. Performance Testing

```bash
# Test page load time
curl -w "@curl-format.txt" -o /dev/null -s https://yourdomain.com

# AB testing (Apache Bench)
ab -n 1000 -c 10 https://yourdomain.com/

# Check database query performance
# Enable query log temporarily
tail -f storage/logs/laravel.log | grep "Query took"
```

### 3. Security Scan

```bash
# Check SSL configuration
curl https://www.ssllabs.com/ssltest/analyze.html?d=yourdomain.com

# Check headers
curl -I https://yourdomain.com

# Scan for vulnerabilities
nikto -h https://yourdomain.com
```

### 4. Monitoring Checklist

- [ ] Application accessible
- [ ] SSL certificate valid
- [ ] Database connected
- [ ] Email sending works
- [ ] File uploads work
- [ ] Cron jobs running
- [ ] Logs being written
- [ ] Backups completing
- [ ] Error rates normal
- [ ] Response times acceptable

---

## 🆘 Troubleshooting

### Issue: 500 Internal Server Error

```bash
# Check error logs
tail -f storage/logs/laravel.log
tail -f /var/log/apache2/error.log  # Apache
tail -f /var/log/nginx/error.log    # Nginx

# Check permissions
ls -la storage/
ls -la bootstrap/cache/

# Clear caches
php artisan cache:clear
php artisan config:clear
php artisan view:clear
```

### Issue: Database Connection Failed

```bash
# Test connection
mysql -u budgetpro_user -p budget_pro

# Check .env configuration
grep DB_ .env

# Verify MySQL is running
sudo systemctl status mysql
```

### Issue: Performance Degradation

```bash
# Check system resources
htop
df -h
free -m

# Check MySQL slow queries
mysql -u root -p
mysql> SHOW PROCESSLIST;
mysql> SHOW FULL PROCESSLIST\G

# Restart services
sudo systemctl restart php8.3-fpm
sudo systemctl restart mysql
sudo systemctl restart apache2  # or nginx
```

---

## 📞 Support

For deployment assistance:
- **Email:** support@budgetpro.com
- **Documentation:** https://docs.budgetpro.com
- **Emergency:** emergency@budgetpro.com (Premium)

---

**Last Updated:** December 9, 2025  
**Version:** 2.0.0
