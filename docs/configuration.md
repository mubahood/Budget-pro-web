# Configuration Guide

- [Overview](#overview)
- [Environment Configuration](#environment-configuration)
- [Application Settings](#application-settings)
- [Database Configuration](#database-configuration)
- [Admin Panel Configuration](#admin-panel-configuration)
- [Email Configuration](#email-configuration)
- [File Storage](#file-storage)
- [Performance Optimization](#performance-optimization)

## Overview

This guide covers all configuration options for Budget Pro, from environment variables to admin panel settings.

### Configuration Files

Budget Pro uses Laravel's configuration system:

```
budget-pro/
├── .env                      # Environment variables (MAIN CONFIG)
├── config/
│   ├── app.php              # Application settings
│   ├── admin.php            # Encore Admin config
│   ├── database.php         # Database connections
│   ├── mail.php             # Email settings
│   ├── filesystems.php      # File storage
│   ├── cache.php            # Cache drivers
│   ├── queue.php            # Queue configuration
│   └── services.php         # Third-party services
```

**Configuration Priority:**
1. `.env` file (highest priority)
2. `config/*.php` files
3. Default values

## Environment Configuration

### The .env File

**Location:** `/path/to/budget-pro/.env`

This is your main configuration file. **Never commit this file to version control!**

### Application Settings

```env
# Application Name (shown in UI)
APP_NAME="Budget Pro"

# Environment: local, staging, production
APP_ENV=production

# Application Key (MUST be generated, never share!)
APP_KEY=base64:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx

# Debug Mode
# true: Show detailed errors (development only!)
# false: Hide errors from users (production)
APP_DEBUG=false

# Application URL (your domain)
APP_URL=https://budget.yourcompany.com

# Application Timezone
APP_TIMEZONE="Africa/Kampala"  # Or your timezone

# Application Locale
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
```

**Timezone Options:**
```
Africa/Kampala (Uganda)
Africa/Nairobi (Kenya)
Africa/Dar_es_Salaam (Tanzania)
Europe/London (UK)
America/New_York (USA East)
America/Los_Angeles (USA West)
Asia/Dubai (UAE)
Asia/Singapore (Singapore)

# Full list: https://www.php.net/manual/en/timezones.php
```

**Locale Options:**
```
en (English - Default)
es (Spanish)
fr (French)
de (German)
# Add more in resources/lang/
```

### Database Settings

```env
# Database Type
DB_CONNECTION=mysql  # mysql, pgsql, sqlite, sqlsrv

# Database Host
DB_HOST=127.0.0.1  # localhost, or remote IP

# Database Port
DB_PORT=3306  # 3306 for MySQL, 5432 for PostgreSQL

# Database Name
DB_DATABASE=budget_pro

# Database Credentials
DB_USERNAME=budget_user
DB_PASSWORD=your_secure_password_here

# Database Options
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
DB_PREFIX=  # Optional table prefix
```

**Multiple Database Connections:**

If you need multiple databases (e.g., separate reporting database):

```env
# Primary database (as above)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=budget_pro

# Secondary database
DB_CONNECTION_REPORTS=mysql
DB_HOST_REPORTS=reports-server.com
DB_DATABASE_REPORTS=budget_reports
DB_USERNAME_REPORTS=reports_user
DB_PASSWORD_REPORTS=reports_password
```

Then configure in `config/database.php`:
```php
'connections' => [
    'mysql' => [ /* primary config */ ],
    'reports' => [
        'driver' => 'mysql',
        'host' => env('DB_HOST_REPORTS'),
        'database' => env('DB_DATABASE_REPORTS'),
        // ... other settings
    ],
],
```

### Admin Panel Configuration

```env
# Admin URL prefix
# Access admin at: https://yoursite.com/admin
ADMIN_ROUTE=admin

# Force HTTPS for admin panel
ADMIN_HTTPS=true

# Admin title
ADMIN_NAME="Budget Pro Admin"

# Admin logo (in public/vendor/laravel-admin/)
ADMIN_LOGO=<b>Budget</b> Pro

# Admin authentication settings
# (In config/admin.php for advanced options)
```

**Advanced Admin Settings:**

Edit `config/admin.php`:

```php
return [
    // Admin route prefix
    'route' => [
        'prefix' => env('ADMIN_ROUTE', 'admin'),
        'namespace' => 'App\\Admin\\Controllers',
        'middleware' => ['web', 'admin'],
    ],

    // Admin install directory
    'directory' => app_path('Admin'),

    // Admin title
    'title' => env('ADMIN_NAME', 'Budget Pro Admin'),

    // Logo
    'logo' => '<b>Budget</b> Pro',

    // Mini logo
    'logo-mini' => '<b>BP</b>',

    // Bootstrap settings
    'bootstrap' => app_path('Admin/bootstrap.php'),

    // Layout
    'layout' => ['color-scheme' => 'dark'], // or 'light'

    // Authentication
    'auth' => [
        'guards' => [
            'admin' => [
                'driver' => 'session',
                'provider' => 'admin',
            ],
        ],
        'providers' => [
            'admin' => [
                'driver' => 'eloquent',
                'model' => App\Models\User::class,
            ],
        ],
    ],

    // Session timeout (minutes)
    'session' => [
        'lifetime' => 120,
    ],

    // Upload settings
    'upload' => [
        'disk' => 'admin',
        'directory' => [
            'image' => 'images',
            'file' => 'files',
        ],
    ],

    // Database settings
    'database' => [
        'users_table' => 'admin_users',
        'roles_table' => 'admin_roles',
        'permissions_table' => 'admin_permissions',
        // ... other tables
    ],
];
```

### Cache & Session

```env
# Cache Driver
# Options: file, redis, memcached, database, array
CACHE_DRIVER=file

# Session Driver
# Options: file, cookie, database, redis, array
SESSION_DRIVER=database

# Session Lifetime (minutes)
SESSION_LIFETIME=120

# Redis (if using Redis)
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=0
```

**Recommended Configurations:**

**Small Site (< 100 users):**
```env
CACHE_DRIVER=file
SESSION_DRIVER=database
```

**Medium Site (100-1000 users):**
```env
CACHE_DRIVER=redis
SESSION_DRIVER=redis
```

**Large Site (1000+ users):**
```env
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

### Queue Configuration

```env
# Queue Driver
# Options: sync, database, redis, beanstalkd, sqs
QUEUE_CONNECTION=database

# Queue name
QUEUE_NAME=default

# Failed jobs
FAILED_QUEUE_DATABASE=mysql
```

**Queue Workers (for async processing):**

```bash
# Start queue worker
php artisan queue:work

# With supervisor (recommended for production)
sudo apt install supervisor
sudo nano /etc/supervisor/conf.d/budget-pro-worker.conf
```

**Supervisor Config:**
```ini
[program:budget-pro-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/budget-pro/artisan queue:work --sleep=3 --tries=3
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/budget-pro/storage/logs/worker.log
stopwaitsecs=3600
```

## Application Settings

### Company Defaults

Configure in admin panel or `config/app.php`:

```php
'company_defaults' => [
    'currency' => 'UGX',
    'currency_symbol' => 'UGX',
    'date_format' => 'Y-m-d',
    'time_format' => 'H:i:s',
    'timezone' => 'Africa/Kampala',
],
```

**Currency Options:**
```php
'currencies' => [
    'UGX' => 'Ugandan Shilling',
    'KES' => 'Kenyan Shilling',
    'TZS' => 'Tanzanian Shilling',
    'USD' => 'US Dollar',
    'EUR' => 'Euro',
    'GBP' => 'British Pound',
    'ZAR' => 'South African Rand',
    'NGN' => 'Nigerian Naira',
],
```

### Locale & Internationalization

**Set System Language:**

1. **Environment:**
```env
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
```

2. **Available Languages:**

Add translations in `resources/lang/`:
```
resources/lang/
├── en/
│   ├── auth.php
│   ├── validation.php
│   └── ...
├── es/
├── fr/
└── ...
```

3. **User-specific Language:**

Users can select preferred language in their profile:
```
Admin → Users → Edit User → Language
```

### Date & Time Formats

**Global Settings:**

```php
// config/app.php
'date_format' => 'Y-m-d',  // 2025-12-09
'time_format' => 'H:i:s',  // 14:30:00
'datetime_format' => 'Y-m-d H:i:s',
```

**Format Options:**
```php
// Date formats
'Y-m-d'      => '2025-12-09'
'd/m/Y'      => '09/12/2025'
'm/d/Y'      => '12/09/2025'
'd-M-Y'      => '09-Dec-2025'
'F j, Y'     => 'December 9, 2025'

// Time formats
'H:i:s'      => '14:30:00' (24-hour)
'h:i:s A'    => '02:30:00 PM' (12-hour)
'H:i'        => '14:30'
```

**Company-Specific:**

Override per company:
```
Admin → Companies → Edit Company → Settings Tab
- Date Format
- Time Format
- Timezone
```

## Email Configuration

### SMTP Configuration

**Gmail:**
```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-specific-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourcompany.com
MAIL_FROM_NAME="${APP_NAME}"
```

**Note:** For Gmail, use [App Passwords](https://support.google.com/accounts/answer/185833), not your regular password.

**Office 365 / Outlook:**
```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.office365.com
MAIL_PORT=587
MAIL_USERNAME=your-email@yourcompany.com
MAIL_PASSWORD=your-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourcompany.com
MAIL_FROM_NAME="${APP_NAME}"
```

**SendGrid:**
```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.sendgrid.net
MAIL_PORT=587
MAIL_USERNAME=apikey
MAIL_PASSWORD=your-sendgrid-api-key
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourcompany.com
MAIL_FROM_NAME="${APP_NAME}"
```

**Mailgun:**
```env
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=mg.yourcompany.com
MAILGUN_SECRET=your-mailgun-api-key
MAILGUN_ENDPOINT=api.mailgun.net
MAIL_FROM_ADDRESS=noreply@yourcompany.com
MAIL_FROM_NAME="${APP_NAME}"
```

**Amazon SES:**
```env
MAIL_MAILER=ses
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_DEFAULT_REGION=us-east-1
MAIL_FROM_ADDRESS=noreply@yourcompany.com
MAIL_FROM_NAME="${APP_NAME}"
```

### Testing Email

**Test Email Configuration:**

```bash
# Using tinker
php artisan tinker

# Send test email
>>> Mail::raw('Test email from Budget Pro', function($message) {
      $message->to('test@example.com')
              ->subject('Test Email');
    });

# Check for errors
# If successful: returns null
# If failed: shows error message
```

**Common Email Issues:**

**Issue: "Connection refused"**
```
Solutions:
- Verify MAIL_HOST and MAIL_PORT
- Check firewall (allow outgoing 587, 465, 25)
- Verify server can make outbound connections
- Check SMTP server is accessible
```

**Issue: "Authentication failed"**
```
Solutions:
- Verify MAIL_USERNAME and MAIL_PASSWORD
- For Gmail: Use App Password, enable "Less secure apps"
- Check credentials are correct
- Verify account is active
```

**Issue: "SSL certificate problem"**
```
Solutions:
- Update CA certificates: sudo apt install ca-certificates
- Or disable verification (not recommended):
  MAIL_ENCRYPTION=null
```

## File Storage

### Storage Configuration

```env
# Default storage disk
FILESYSTEM_DISK=local

# Options: local, public, s3, ftp, sftp
```

**Storage Disks:**

Configured in `config/filesystems.php`:

```php
'disks' => [
    // Local (private, not web-accessible)
    'local' => [
        'driver' => 'local',
        'root' => storage_path('app'),
    ],

    // Public (web-accessible)
    'public' => [
        'driver' => 'local',
        'root' => storage_path('app/public'),
        'url' => env('APP_URL').'/storage',
        'visibility' => 'public',
    ],

    // Amazon S3
    's3' => [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'bucket' => env('AWS_BUCKET'),
    ],
],
```

### Using Amazon S3

**Setup:**

1. **Install SDK:**
```bash
composer require league/flysystem-aws-s3-v3 "^3.0"
```

2. **Configure .env:**
```env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-bucket-name
AWS_URL=https://your-bucket.s3.amazonaws.com
```

3. **Update admin config:**
```php
// config/admin.php
'upload' => [
    'disk' => 's3',
    'directory' => [
        'image' => 'images',
        'file' => 'files',
    ],
],
```

### File Upload Limits

**PHP Configuration:**

Edit `php.ini`:
```ini
upload_max_filesize = 100M
post_max_size = 100M
max_execution_time = 300
memory_limit = 256M
```

**Web Server:**

**Apache (`.htaccess`):**
```apache
php_value upload_max_filesize 100M
php_value post_max_size 100M
```

**Nginx:**
```nginx
client_max_body_size 100M;
```

**Verify Changes:**
```bash
php -i | grep upload_max_filesize
```

## Performance Optimization

### Production Optimization

**Run these commands in production:**

```bash
# Cache configuration
php artisan config:cache

# Cache routes
php artisan route:cache

# Cache views
php artisan view:cache

# Optimize autoloader
composer install --optimize-autoloader --no-dev

# Asset compilation
npm run build  # production build
```

### Caching Strategy

**Config Cache:**
```bash
# Cache all config files
php artisan config:cache

# Clear config cache
php artisan config:clear
```

**Route Cache:**
```bash
# Cache routes (only for production!)
php artisan route:cache

# Clear route cache
php artisan route:clear
```

**View Cache:**
```bash
# Cache Blade templates
php artisan view:cache

# Clear view cache
php artisan view:clear
```

**Application Cache:**
```bash
# Clear application cache
php artisan cache:clear

# Cache specific data
Cache::put('key', 'value', $seconds);
```

### Database Optimization

**Indexing:**

Already optimized, but verify:
```sql
SHOW INDEX FROM stock_items;
SHOW INDEX FROM sale_records;
```

**Query Optimization:**

Use eager loading:
```php
// Bad (N+1 problem)
$sales = SaleRecord::all();
foreach ($sales as $sale) {
    echo $sale->stock_item->name; // Queries for each item
}

// Good (Eager loading)
$sales = SaleRecord::with('stock_item')->get();
foreach ($sales as $sale) {
    echo $sale->stock_item->name; // No additional queries
}
```

**Connection Pooling:**

For high traffic, use persistent connections:
```env
DB_CONNECTION=mysql
DB_PERSISTENT=true
```

### OPcache Configuration

**Enable OPcache:**

Edit `php.ini`:
```ini
[opcache]
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
opcache.fast_shutdown=1
```

**Verify:**
```bash
php -i | grep opcache
```

## Next Steps

- **[Company Settings](/docs/company-settings.md)** - Configure your company
- **[User Management](/docs/users.md)** - Manage users and permissions
- **[Quick Start](/docs/quickstart.md)** - Start using the system

---

> **Pro Tip**: Always test configuration changes in a staging environment before applying to production. Keep backups of your .env file.
