# Installation Guide

- [Overview](#overview)
- [System Requirements](#system-requirements)
- [Pre-Installation Checklist](#pre-installation-checklist)
- [Installation Methods](#installation-methods)
- [Post-Installation Setup](#post-installation-setup)
- [Troubleshooting](#troubleshooting)

## Overview

Budget Pro installation takes approximately 10-15 minutes. This guide covers manual installation, Docker deployment, and server configurations.

### What You'll Need

- Web server (Apache/Nginx)
- PHP 8.1+ with required extensions
- MySQL 5.7+ or MariaDB 10.3+
- Composer (PHP dependency manager)
- Node.js & NPM (for frontend assets)
- Terminal/SSH access

## System Requirements

### Server Requirements

**Minimum Specifications:**
```yaml
Processor: 2 CPU cores
RAM: 2GB
Storage: 5GB available space
OS: Linux (Ubuntu 20.04+, CentOS 7+) or Windows Server 2016+
```

**Recommended Specifications:**
```yaml
Processor: 4 CPU cores
RAM: 4GB+
Storage: 20GB SSD
OS: Ubuntu 22.04 LTS
Load Balancer: For high traffic (optional)
```

### PHP Requirements

**Version:** PHP 8.1, 8.2, or 8.3

**Required Extensions:**
```
✅ php-cli
✅ php-common
✅ php-curl
✅ php-json
✅ php-mbstring
✅ php-mysql
✅ php-xml
✅ php-zip
✅ php-gd (for image processing)
✅ php-bcmath (for precise calculations)
✅ php-intl (for internationalization)
✅ php-fileinfo
```

**Check PHP Version:**
```bash
php -v
# Should show: PHP 8.1.x or higher
```

**Check Extensions:**
```bash
php -m
# Should list all required extensions
```

**Install Missing Extensions (Ubuntu):**
```bash
sudo apt update
sudo apt install php8.2-cli php8.2-common php8.2-curl \
  php8.2-json php8.2-mbstring php8.2-mysql php8.2-xml \
  php8.2-zip php8.2-gd php8.2-bcmath php8.2-intl
```

### Database Requirements

**MySQL:**
```yaml
Version: 5.7 or higher
Recommended: 8.0+
Charset: utf8mb4
Collation: utf8mb4_unicode_ci
```

**MariaDB:**
```yaml
Version: 10.3 or higher
Recommended: 10.6+
Charset: utf8mb4
Collation: utf8mb4_unicode_ci
```

**PostgreSQL:** Not currently supported (planned for v3.0)

### Web Server

**Apache 2.4+:**
```apache
Required Modules:
- mod_rewrite
- mod_headers
- mod_ssl (for HTTPS)
```

**Nginx 1.18+:**
```nginx
Required:
- PHP-FPM configured
- Proper rewrite rules
- SSL configured (recommended)
```

### Additional Software

**Composer:**
```bash
# Check version
composer --version
# Should be 2.x

# Install if missing
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

**Node.js & NPM:**
```bash
# Check versions
node -v  # Should be 16.x or higher
npm -v   # Should be 8.x or higher

# Install NVM (Node Version Manager)
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.0/install.sh | bash
nvm install 18
nvm use 18
```

### Browser Requirements

**Supported Browsers:**
- ✅ Chrome 90+ (Recommended)
- ✅ Firefox 88+
- ✅ Safari 14+
- ✅ Edge 90+
- ❌ Internet Explorer (Not supported)

## Pre-Installation Checklist

### Before You Begin

**☐ Server Access**
```bash
# Ensure you have SSH access
ssh user@your-server.com

# And sudo privileges
sudo -v
```

**☐ Domain Name (Optional but recommended)**
```
Example: budget.yourcompany.com
DNS configured and pointing to server
```

**☐ SSL Certificate (Highly recommended)**
```
Options:
- Let's Encrypt (Free)
- Commercial SSL certificate
- Self-signed (development only)
```

**☐ Database Created**
```sql
CREATE DATABASE budget_pro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'budget_user'@'localhost' IDENTIFIED BY 'secure_password';
GRANT ALL PRIVILEGES ON budget_pro.* TO 'budget_user'@'localhost';
FLUSH PRIVILEGES;
```

**☐ Backup Existing Data**
```bash
# If upgrading from previous version
mysqldump -u root -p old_database > backup.sql
tar -czf files_backup.tar.gz /path/to/old/installation
```

## Installation Methods

### Method 1: Manual Installation (Recommended)

**Step 1: Download**
```bash
# Navigate to web root
cd /var/www

# Option A: Download from Envato
# Download zip file and upload to server

# Option B: Clone from repository (if access provided)
git clone https://github.com/yourrepo/budget-pro.git
cd budget-pro

# Option C: Extract uploaded zip
unzip budget-pro-v2.0.0.zip
cd budget-pro
```

**Step 2: Set Permissions**
```bash
# Set ownership (replace www-data with your web server user)
sudo chown -R www-data:www-data /var/www/budget-pro

# Set directory permissions
sudo find /var/www/budget-pro -type d -exec chmod 755 {} \;

# Set file permissions
sudo find /var/www/budget-pro -type f -exec chmod 644 {} \;

# Make storage and cache writable
sudo chmod -R 775 storage bootstrap/cache
```

**Step 3: Install Dependencies**
```bash
# Install PHP dependencies
composer install --optimize-autoloader --no-dev

# Install Node dependencies
npm install

# Build frontend assets
npm run build
```

**Step 4: Configure Environment**
```bash
# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Edit configuration
nano .env
```

**Environment Configuration (.env):**
```env
# Application
APP_NAME="Budget Pro"
APP_ENV=production
APP_KEY=base64:xxxxxxxxxxxxx  # Generated by key:generate
APP_DEBUG=false
APP_URL=https://budget.yourcompany.com

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=budget_pro
DB_USERNAME=budget_user
DB_PASSWORD=your_secure_password

# Admin Panel
ADMIN_HTTPS=true
ADMIN_ROUTE=admin

# Session
SESSION_DRIVER=database
SESSION_LIFETIME=120

# Cache
CACHE_DRIVER=file
QUEUE_CONNECTION=database

# Mail (for notifications)
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your@email.com
MAIL_PASSWORD=your_email_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourcompany.com
MAIL_FROM_NAME="Budget Pro"

# File Storage
FILESYSTEM_DISK=local
```

**Step 5: Database Setup**
```bash
# Run migrations
php artisan migrate --force

# Seed initial data (admin user, demo company)
php artisan db:seed --class=InitialSeeder

# Or full demo data (optional)
php artisan db:seed --class=DemoSeeder
```

**Step 6: Configure Web Server**

**Apache Configuration:**
```bash
# Create virtual host
sudo nano /etc/apache2/sites-available/budget-pro.conf
```

```apache
<VirtualHost *:80>
    ServerName budget.yourcompany.com
    ServerAdmin admin@yourcompany.com
    DocumentRoot /var/www/budget-pro/public

    <Directory /var/www/budget-pro/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/budget-pro-error.log
    CustomLog ${APACHE_LOG_DIR}/budget-pro-access.log combined
</VirtualHost>
```

```bash
# Enable site and rewrite module
sudo a2enmod rewrite
sudo a2ensite budget-pro.conf
sudo systemctl restart apache2
```

**Nginx Configuration:**
```bash
# Create server block
sudo nano /etc/nginx/sites-available/budget-pro
```

```nginx
server {
    listen 80;
    server_name budget.yourcompany.com;
    root /var/www/budget-pro/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
# Enable site
sudo ln -s /etc/nginx/sites-available/budget-pro /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx
```

**Step 7: Setup SSL (Let's Encrypt)**
```bash
# Install Certbot
sudo apt install certbot python3-certbot-apache  # For Apache
# OR
sudo apt install certbot python3-certbot-nginx   # For Nginx

# Obtain certificate
sudo certbot --apache -d budget.yourcompany.com  # Apache
# OR
sudo certbot --nginx -d budget.yourcompany.com   # Nginx

# Auto-renewal (check)
sudo certbot renew --dry-run
```

**Step 8: Optimize for Production**
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

**Step 9: Setup Scheduler (Cron)**
```bash
# Edit crontab
crontab -e

# Add Laravel scheduler
* * * * * cd /var/www/budget-pro && php artisan schedule:run >> /dev/null 2>&1
```

**Step 10: Test Installation**
```bash
# Visit in browser
https://budget.yourcompany.com

# You should see login page
# Default credentials (change immediately!):
# Email: admin@budgetpro.test
# Password: password
```

### Method 2: Docker Installation

**Step 1: Install Docker**
```bash
# Install Docker
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh

# Install Docker Compose
sudo curl -L "https://github.com/docker/compose/releases/download/v2.20.0/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose
```

**Step 2: Prepare Files**
```bash
# Extract Budget Pro
cd /opt
unzip budget-pro-v2.0.0.zip
cd budget-pro

# Copy environment
cp .env.example .env
nano .env  # Configure as needed
```

**Step 3: Docker Compose**

Create `docker-compose.yml`:
```yaml
version: '3.8'

services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: budget-pro-app
    restart: unless-stopped
    working_dir: /var/www
    volumes:
      - ./:/var/www
    networks:
      - budget-network

  webserver:
    image: nginx:alpine
    container_name: budget-pro-nginx
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./:/var/www
      - ./docker/nginx:/etc/nginx/conf.d
    networks:
      - budget-network

  db:
    image: mysql:8.0
    container_name: budget-pro-db
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: ${DB_DATABASE}
      MYSQL_ROOT_PASSWORD: ${DB_PASSWORD}
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_USER: ${DB_USERNAME}
    volumes:
      - dbdata:/var/lib/mysql
    networks:
      - budget-network

networks:
  budget-network:
    driver: bridge

volumes:
  dbdata:
    driver: local
```

**Step 4: Start Containers**
```bash
# Build and start
docker-compose up -d

# Install dependencies
docker-compose exec app composer install
docker-compose exec app npm install && npm run build

# Generate key
docker-compose exec app php artisan key:generate

# Run migrations
docker-compose exec app php artisan migrate --seed

# Set permissions
docker-compose exec app chown -R www-data:www-data /var/www
```

**Step 5: Access Application**
```
http://localhost
or
http://your-server-ip
```

### Method 3: Shared Hosting (cPanel)

**Step 1: Requirements Check**
- PHP 8.1+ available
- Access to Terminal (or SSH)
- MySQL database access
- Composer access (or download dependencies separately)

**Step 2: Upload Files**
```
1. Download Budget Pro zip
2. Login to cPanel
3. Go to File Manager
4. Navigate to public_html (or subdirectory)
5. Upload zip file
6. Extract
```

**Step 3: Create Database**
```
1. Go to MySQL Databases
2. Create new database: username_budgetpro
3. Create user: username_budget
4. Set password (strong!)
5. Add user to database (ALL PRIVILEGES)
```

**Step 4: Configure**
```
1. Edit .env file (use File Manager editor)
2. Update database credentials
3. Set APP_URL to your domain
4. Save
```

**Step 5: Install Dependencies**

**If Composer available:**
```bash
# Via Terminal in cPanel
cd public_html/budget-pro
composer install --optimize-autoloader --no-dev
```

**If no Composer:**
```
1. Download vendor.zip (provided separately)
2. Upload to budget-pro/
3. Extract
```

**Step 6: Run Setup**
```bash
# Via Terminal
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
```

**Step 7: Set Permissions**
```
chmod -R 755 storage bootstrap/cache
```

**Step 8: Configure Domain**
```
If in subdirectory: https://yourdomain.com/budget-pro/public
If in root: Update document root to public/ folder
```

## Post-Installation Setup

### First Login

**Access admin panel:**
```
URL: https://your-domain.com/admin
Email: admin@budgetpro.test
Password: password
```

**⚠️ CRITICAL: Change default password immediately!**

### Initial Configuration

**1. Update Admin Profile**
```
Admin → Users → Admin User → Edit
- Change name
- Update email
- Set strong password
- Add profile photo (optional)
```

**2. Configure Company**
```
Admin → Companies → Demo Company → Edit
- Update company name
- Add logo
- Set address, phone, email
- Configure financial settings
```

**3. Create Financial Period**
```
Admin → Financial Periods → New
- Start Date: Beginning of fiscal year
- End Date: End of fiscal year
- Status: Active
- Save
```

**4. Setup Email**
```
.env file:
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-email
MAIL_PASSWORD=your-password

Test:
php artisan tinker
>>> Mail::raw('Test email', function($m) {
      $m->to('test@example.com')->subject('Test');
    });
```

**5. Configure Backup**
```bash
# Install backup package (if not included)
composer require spatie/laravel-backup

# Publish config
php artisan vendor:publish --provider="Spatie\Backup\BackupServiceProvider"

# Configure in config/backup.php
# Setup cron:
0 2 * * * cd /var/www/budget-pro && php artisan backup:run
```

### Security Hardening

**1. Environment File**
```bash
# Protect .env
chmod 600 .env

# Hide from web
# (Already in .htaccess, but verify)
```

**2. Disable Debug Mode**
```env
APP_DEBUG=false
APP_ENV=production
```

**3. Force HTTPS**
```php
// In app/Providers/AppServiceProvider.php boot()
if ($this->app->environment('production')) {
    \URL::forceScheme('https');
}
```

**4. Setup Firewall**
```bash
# UFW (Ubuntu)
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

**5. Disable Unused Services**
```bash
# List services
systemctl list-units --type=service

# Disable if not needed
sudo systemctl disable service-name
```

**6. Regular Updates**
```bash
# System updates
sudo apt update && sudo apt upgrade

# Application updates
# (Follow upgrade guide when new versions release)
```

## Troubleshooting

### Common Installation Issues

**Issue: "Could not find driver" (PDO)**
```bash
Solution:
sudo apt install php8.2-mysql
sudo systemctl restart apache2
```

**Issue: "Permission denied" errors**
```bash
Solution:
sudo chown -R www-data:www-data /var/www/budget-pro
sudo chmod -R 775 storage bootstrap/cache
```

**Issue: "500 Internal Server Error"**
```bash
Solutions:
1. Check error logs:
   tail -f storage/logs/laravel.log
   
2. Clear cache:
   php artisan cache:clear
   php artisan config:clear
   php artisan view:clear
   
3. Check file permissions
4. Verify .env configuration
5. Check web server error logs
```

**Issue: White screen / blank page**
```bash
Solutions:
1. Enable display_errors temporarily:
   nano .env
   APP_DEBUG=true
   
2. Check PHP error log:
   tail -f /var/log/php8.2-fpm.log
   
3. Verify APP_KEY is set:
   php artisan key:generate
```

**Issue: "Class not found"**
```bash
Solution:
composer dump-autoload
php artisan clear-compiled
php artisan config:cache
```

**Issue: Database connection failed**
```bash
Solutions:
1. Verify database exists:
   mysql -u root -p
   SHOW DATABASES;
   
2. Check credentials in .env
3. Test connection:
   php artisan tinker
   >>> DB::connection()->getPdo();
   
4. Check MySQL is running:
   sudo systemctl status mysql
```

**Issue: Assets not loading (404)**
```bash
Solutions:
1. Run npm build:
   npm install
   npm run build
   
2. Clear cache:
   php artisan view:clear
   
3. Check public/build/ exists
4. Verify .htaccess or nginx config
```

**Issue: Scheduler not running**
```bash
Solutions:
1. Verify cron entry:
   crontab -l
   
2. Check cron service:
   sudo systemctl status cron
   
3. Test manually:
   php artisan schedule:run
   
4. Check logs:
   tail -f storage/logs/laravel.log
```

### Getting Help

**Before contacting support:**
- Check error logs
- Try clearing cache
- Verify requirements met
- Search documentation
- Check FAQ

**Support Channels:**
- Documentation: [Your docs URL]
- Support Ticket: support@yourcompany.com
- Community Forum: [Forum URL]
- GitHub Issues: [If applicable]

**Include in support request:**
- PHP version: `php -v`
- Laravel version: `php artisan --version`
- Error message (full stack trace)
- Steps to reproduce
- Server environment details

## Next Steps

- **[Configuration Guide](/docs/configuration.md)** - Configure application settings
- **[Company Settings](/docs/company-settings.md)** - Setup your company
- **[Quick Start](/docs/quickstart.md)** - Get started in 5 minutes

---

> **Pro Tip**: Always backup your database and files before performing updates or making major configuration changes.
