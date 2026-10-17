# Budget Pro - Installation Guide

**Version:** 2.0.0  
**Last Updated:** December 9, 2025  
**Estimated Installation Time:** 10-15 minutes

---

## 📋 Table of Contents

1. [Server Requirements](#server-requirements)
2. [Pre-Installation Checklist](#pre-installation-checklist)
3. [Installation Methods](#installation-methods)
4. [Quick Installation (Recommended)](#quick-installation-recommended)
5. [Manual Installation](#manual-installation)
6. [Post-Installation Setup](#post-installation-setup)
7. [Demo Data Installation](#demo-data-installation)
8. [Troubleshooting](#troubleshooting)
9. [Security Hardening](#security-hardening)
10. [Updating Budget Pro](#updating-budget-pro)

---

## 🖥️ Server Requirements

### Minimum Requirements

**Web Server:**
- Apache 2.4+ or Nginx 1.18+
- mod_rewrite enabled (Apache)

**PHP:**
- Version: 8.1 or higher (8.2+ recommended)
- Extensions Required:
  - BCMath
  - Ctype
  - JSON
  - Mbstring
  - OpenSSL
  - PDO
  - Tokenizer
  - XML
  - GD or Imagick
  - Fileinfo
  - Zip

**Database:**
- MySQL 5.7+ or MariaDB 10.3+
- PostgreSQL 10+ (alternative)

**PHP Configuration:**
```ini
memory_limit = 512M
upload_max_filesize = 64M
post_max_size = 64M
max_execution_time = 300
```

### Recommended Requirements

- **PHP:** 8.3+
- **Memory:** 1GB RAM minimum, 2GB+ recommended
- **Storage:** 5GB minimum, 10GB+ recommended
- **SSL Certificate:** Required for production
- **Composer:** 2.5+
- **Node.js:** 18+ (for asset compilation)

---

## ✅ Pre-Installation Checklist

Before beginning installation, ensure you have:

- [ ] Root/admin access to your server
- [ ] FTP/SSH credentials
- [ ] MySQL database created
- [ ] Database username and password
- [ ] Domain/subdomain configured (optional but recommended)
- [ ] SSL certificate installed (recommended)
- [ ] Composer installed on server
- [ ] PHP version verified (8.1+)
- [ ] All required PHP extensions installed
- [ ] File permissions configured (775 for directories, 664 for files)

---

## 🚀 Installation Methods

Budget Pro offers two installation methods:

### Method 1: Quick Installation (Recommended)
- **Time:** 5-10 minutes
- **Difficulty:** Easy
- **Best for:** Most users
- **Requirements:** Command line access

### Method 2: Manual Installation
- **Time:** 15-20 minutes
- **Difficulty:** Moderate
- **Best for:** Custom setups, shared hosting
- **Requirements:** FTP access minimum

---

## ⚡ Quick Installation (Recommended)

### Step 1: Download and Extract

```bash
# Navigate to your web root
cd /var/www/html

# Download Budget Pro (replace with your download link)
wget https://downloads.budgetpro.com/budget-pro-v2.0.0.zip

# Extract files
unzip budget-pro-v2.0.0.zip

# Navigate to project directory
cd budget-pro

# Set correct permissions
chmod -R 775 storage bootstrap/cache
chmod -R 775 public
```

### Step 2: Configure Environment

```bash
# Copy environment file
cp .env.example .env

# Edit environment file
nano .env
```

Update the following values in `.env`:

```env
APP_NAME="Budget Pro"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database_name
DB_USERNAME=your_database_user
DB_PASSWORD=your_database_password

MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_mail_username
MAIL_PASSWORD=your_mail_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"
```

### Step 3: Install Dependencies

```bash
# Install PHP dependencies
composer install --optimize-autoloader --no-dev

# Generate application key
php artisan key:generate

# Run database migrations
php artisan migrate --force

# Create storage link
php artisan storage:link

# Cache configuration
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Step 4: Build Frontend Assets (Optional)

```bash
# Install Node dependencies
npm install

# Build assets for production
npm run build
```

### Step 5: Create Admin User

```bash
# Create first admin user
php artisan db:seed --class=AdminUserSeeder

# Or manually via artisan
php artisan tinker
>>> $user = new App\Models\User();
>>> $user->first_name = 'Admin';
>>> $user->last_name = 'User';
>>> $user->email = 'admin@admin.com';
>>> $user->username = 'admin@admin.com';
>>> $user->password = bcrypt('admin');
>>> $user->save();
>>> $user->roles()->attach(1);
>>> exit
```

### Step 6: Configure Web Server

**For Apache (.htaccess already included):**

Make sure `mod_rewrite` is enabled:
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

Point your virtual host to the `public` directory:
```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    DocumentRoot /var/www/html/budget-pro/public
    
    <Directory /var/www/html/budget-pro/public>
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/budgetpro_error.log
    CustomLog ${APACHE_LOG_DIR}/budgetpro_access.log combined
</VirtualHost>
```

**For Nginx:**

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /var/www/html/budget-pro/public;

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

### Step 7: Access Your Installation

1. Open your browser and navigate to your domain
2. Login with default credentials:
   - **Email:** admin@admin.com
   - **Password:** admin
3. **Important:** Change the default password immediately!

---

## 🔧 Manual Installation

### Step 1: Upload Files

1. Download Budget Pro package
2. Extract the ZIP file on your local computer
3. Upload all files to your web root via FTP/SFTP
4. Ensure `public` directory is set as document root

### Step 2: Create Database

1. Login to your hosting control panel (cPanel/Plesk)
2. Navigate to MySQL Databases
3. Create a new database (e.g., `budgetpro_db`)
4. Create a database user with all privileges
5. Note down: database name, username, password

### Step 3: Configure Application

1. Rename `.env.example` to `.env`
2. Edit `.env` with text editor:
   - Set `APP_URL` to your domain
   - Update database credentials
   - Set `APP_DEBUG=false` for production
3. Save changes

### Step 4: Run Installation via Browser

Visit: `https://yourdomain.com/install.php`

> **Note:** Installation wizard will be available in v2.1. For now, use command line or request installation assistance.

### Step 5: Manual Database Setup (If no SSH access)

1. Export the `database.sql` file from package
2. Import via phpMyAdmin:
   - Select your database
   - Click "Import" tab
   - Choose `database.sql` file
   - Click "Go"

### Step 6: Generate Application Key

If you don't have command line access, add this to `.env`:
```
APP_KEY=base64:YOUR_GENERATED_KEY_HERE
```

Generate key online: https://generate-random.org/laravel-key-generator

---

## 🎯 Post-Installation Setup

### 1. Change Default Credentials

**Critical Security Step!**

1. Login with default credentials
2. Navigate to: Settings → My Profile
3. Update:
   - Email address
   - Password (strong password)
   - Personal information

### 2. Configure Company Settings

1. Go to: Settings → Company Settings
2. Fill in:
   - Company name
   - Contact information
   - Address
   - Logo (upload your logo)
   - Currency
   - Tax settings

### 3. Setup Email Configuration

1. Navigate to: Settings → Email Settings
2. Configure SMTP settings:
   - Host, Port, Username, Password
   - Test email sending

### 4. Configure Backup

```bash
# Setup daily backup cron job
crontab -e

# Add this line
0 2 * * * cd /var/www/html/budget-pro && php artisan backup:run
```

### 5. Setup Task Scheduler (Important!)

Budget Pro uses Laravel's scheduler for automated tasks.

Add to crontab:
```bash
* * * * * cd /var/www/html/budget-pro && php artisan schedule:run >> /dev/null 2>&1
```

### 6. Configure File Permissions

```bash
# Set correct ownership
sudo chown -R www-data:www-data /var/www/html/budget-pro

# Set directory permissions
sudo find /var/www/html/budget-pro -type d -exec chmod 755 {} \;

# Set file permissions
sudo find /var/www/html/budget-pro -type f -exec chmod 644 {} \;

# Storage and cache need write access
sudo chmod -R 775 /var/www/html/budget-pro/storage
sudo chmod -R 775 /var/www/html/budget-pro/bootstrap/cache
```

---

## 🎭 Demo Data Installation

Want to explore Budget Pro with sample data?

### Quick Demo Setup

```bash
# Navigate to project directory
cd /var/www/html/budget-pro

# Run demo seeder
php artisan db:seed --class=CompleteDemoSeeder

# Or use NPM script
npm run demo-seed
```

### What Demo Data Includes

The demo seeder creates:

**3 Complete Demo Companies:**
1. **TechStore Electronics** - Technology retail business
2. **Fashion Hub Boutique** - Fashion retail store
3. **MediCare Pharmacy** - Healthcare pharmacy

**Each Company Includes:**
- 3 Users (Owner, Sales Manager, Stock Keeper)
- 3 Stock categories with 9 subcategories
- 100-135 realistic products with SKUs and barcodes
- 1 Active financial period
- 20-30 Purchase orders
- 100-200 Sale records
- 30-50 Expense records

**Total Demo Data:**
- 9 Users across 3 companies
- 300-400 Stock items
- 300-600 Sale transactions
- 60-90 Purchase orders
- 90-150 Expense records

**Demo Login Credentials:**
```
Company: TechStore Electronics
Email: info@techstore.demo
Password: password123

Company: Fashion Hub Boutique
Email: contact@fashionhub.demo
Password: password123

Company: MediCare Pharmacy
Email: support@medicare.demo
Password: password123
```

### Resetting Demo Data

```bash
# Clear all data and reseed
php artisan migrate:fresh
php artisan db:seed --class=CompleteDemoSeeder
```

---

## 🔧 Troubleshooting

### Common Issues and Solutions

#### Issue 1: White Screen / 500 Error

**Symptoms:** Blank page or "500 Internal Server Error"

**Solutions:**
```bash
# Check error logs
tail -f storage/logs/laravel.log

# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Regenerate autoload files
composer dump-autoload

# Check file permissions
chmod -R 775 storage bootstrap/cache
```

#### Issue 2: Database Connection Failed

**Symptoms:** "SQLSTATE[HY000] [2002] Connection refused"

**Solutions:**
1. Verify database credentials in `.env`
2. Check if MySQL is running: `sudo systemctl status mysql`
3. Test database connection:
```bash
mysql -u your_username -p
```
4. Ensure database exists:
```sql
SHOW DATABASES;
CREATE DATABASE budgetpro_db;
```

#### Issue 3: Permission Denied Errors

**Symptoms:** "Permission denied" when accessing files

**Solutions:**
```bash
# Fix ownership
sudo chown -R www-data:www-data /var/www/html/budget-pro

# Fix permissions
sudo chmod -R 775 storage bootstrap/cache
```

#### Issue 4: Missing PHP Extensions

**Symptoms:** "Extension not found" errors

**Solutions:**
```bash
# Ubuntu/Debian
sudo apt-get install php8.2-{bcmath,xml,mbstring,gd,zip,mysql}

# CentOS/RHEL
sudo yum install php82-{bcmath,xml,mbstring,gd,zip,mysqlnd}

# Restart web server
sudo systemctl restart apache2
# or
sudo systemctl restart nginx
```

#### Issue 5: Composer Install Fails

**Symptoms:** Composer errors during `composer install`

**Solutions:**
```bash
# Update composer
composer self-update

# Clear composer cache
composer clear-cache

# Install with verbose output
composer install -vvv --no-dev

# Increase memory limit
php -d memory_limit=512M /usr/local/bin/composer install
```

#### Issue 6: Assets Not Loading (CSS/JS)

**Symptoms:** Page displays without styling

**Solutions:**
```bash
# Rebuild assets
npm install
npm run build

# Create storage link
php artisan storage:link

# Clear browser cache
# Check browser console for errors
```

#### Issue 7: Email Not Sending

**Symptoms:** Emails not being delivered

**Solutions:**
1. Verify SMTP credentials in `.env`
2. Test email configuration:
```bash
php artisan tinker
>>> Mail::raw('Test email', function($msg) {
    $msg->to('test@example.com')->subject('Test');
});
```
3. Check mail logs: `tail -f storage/logs/laravel.log`
4. Try alternative mail driver (e.g., Mailgun, SendGrid)

### Getting Help

If issues persist:

1. **Check Documentation:** docs.budgetpro.com
2. **Error Logs:** Always check `storage/logs/laravel.log`
3. **Server Logs:** Check Apache/Nginx error logs
4. **Contact Support:** support@budgetpro.com
5. **Forum:** community.budgetpro.com

---

## 🔒 Security Hardening

### Essential Security Steps

#### 1. Environment File Protection

Ensure `.env` is not publicly accessible:

**Apache (.htaccess):**
```apache
<Files .env>
    Order allow,deny
    Deny from all
</Files>
```

**Nginx:**
```nginx
location ~ /\.env {
    deny all;
    return 404;
}
```

#### 2. Disable Debug Mode

In `.env`:
```env
APP_DEBUG=false
APP_ENV=production
```

#### 3. Force HTTPS

**Apache:**
```apache
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

**Nginx:**
```nginx
if ($scheme != "https") {
    return 301 https://$server_name$request_uri;
}
```

#### 4. Setup Firewall

```bash
# Allow HTTP and HTTPS
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# Allow SSH (if needed)
sudo ufw allow 22/tcp

# Enable firewall
sudo ufw enable
```

#### 5. Regular Updates

```bash
# Update dependencies weekly
composer update
npm update

# Apply security patches
php artisan migrate
```

#### 6. Backup Strategy

**Daily automated backups:**
```bash
# Install backup package
composer require spatie/laravel-backup

# Configure in config/backup.php
# Setup cron: 0 2 * * * php artisan backup:run
```

#### 7. Restrict Admin Access

- Use strong passwords (16+ characters)
- Enable 2FA (Two-Factor Authentication)
- Limit admin user accounts
- Monitor login attempts
- Regular security audits

---

## 🔄 Updating Budget Pro

### Before Updating

1. **Backup Everything:**
```bash
# Backup database
mysqldump -u username -p database_name > backup.sql

# Backup files
tar -czf budget-pro-backup.tar.gz /var/www/html/budget-pro
```

2. **Review Changelog:** Check for breaking changes
3. **Test on Staging:** Never update production directly

### Update Process

```bash
# 1. Enable maintenance mode
php artisan down

# 2. Pull latest version
git pull origin main
# or download and extract new version

# 3. Update dependencies
composer install --no-dev --optimize-autoloader
npm install && npm run build

# 4. Run migrations
php artisan migrate --force

# 5. Clear caches
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 6. Disable maintenance mode
php artisan up
```

### Rollback (If Issues Occur)

```bash
# 1. Enable maintenance mode
php artisan down

# 2. Restore database backup
mysql -u username -p database_name < backup.sql

# 3. Restore files
rm -rf /var/www/html/budget-pro
tar -xzf budget-pro-backup.tar.gz

# 4. Disable maintenance mode
php artisan up
```

---

## 📞 Support

### Need Help?

- **Email:** support@budgetpro.com
- **Documentation:** https://docs.budgetpro.com
- **Community Forum:** https://community.budgetpro.com
- **Video Tutorials:** https://youtube.com/budgetpro

### Support Hours

- Monday - Friday: 9 AM - 6 PM EST
- Response Time: 24-48 hours
- Emergency Support: Available for Premium customers

---

## ✅ Post-Installation Checklist

- [ ] Application installed successfully
- [ ] Database configured and migrated
- [ ] Admin user created and password changed
- [ ] Company settings configured
- [ ] Email settings tested
- [ ] SSL certificate installed
- [ ] Backup system configured
- [ ] Task scheduler setup
- [ ] File permissions correct
- [ ] Debug mode disabled
- [ ] All features tested
- [ ] Demo data reviewed (if installed)

**Congratulations! Budget Pro is now ready to use! 🎉**

---

**Installation Guide Version:** 2.0.0  
**Last Updated:** December 9, 2025  
**Next Update:** With Installation Wizard (v2.1)
