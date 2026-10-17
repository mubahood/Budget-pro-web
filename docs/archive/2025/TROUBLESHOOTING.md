# Budget Pro - Troubleshooting & FAQ

**Version:** 2.0.0  
**Last Updated:** December 9, 2025

---

## 📋 Table of Contents

1. [Common Issues](#common-issues)
2. [Installation Problems](#installation-problems)
3. [Database Issues](#database-issues)
4. [Performance Problems](#performance-problems)
5. [Sales & Stock Issues](#sales--stock-issues)
6. [Multi-Tenant Issues](#multi-tenant-issues)
7. [API Problems](#api-problems)
8. [Email Issues](#email-issues)
9. [Frequently Asked Questions](#frequently-asked-questions)
10. [Getting Additional Help](#getting-additional-help)

---

## 🔧 Common Issues

### Issue: White Screen After Installation

**Symptoms:** Blank white page when accessing the application

**Common Causes:**
1. PHP errors with display_errors disabled
2. Missing `.env` file
3. Wrong file permissions
4. Missing PHP extensions

**Solutions:**

```bash
# 1. Check Laravel logs
tail -f storage/logs/laravel.log

# 2. Enable debug mode temporarily
# In .env file
APP_DEBUG=true

# 3. Check file permissions
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# 4. Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# 5. Regenerate autoload
composer dump-autoload
```

---

### Issue: 404 Not Found on All Routes

**Symptoms:** Only homepage works, all other pages show 404

**Common Causes:**
1. `mod_rewrite` not enabled (Apache)
2. `.htaccess` not working
3. Document root not set to `public` directory
4. Nginx configuration missing

**Solutions:**

**For Apache:**
```bash
# Enable mod_rewrite
sudo a2enmod rewrite
sudo systemctl restart apache2

# Check .htaccess exists in public folder
ls -la public/.htaccess

# Update VirtualHost AllowOverride
<Directory /var/www/html/budget-pro/public>
    AllowOverride All
</Directory>
```

**For Nginx:**
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

---

### Issue: "No application encryption key has been specified"

**Symptoms:** Error message about missing encryption key

**Solution:**
```bash
# Generate new application key
php artisan key:generate

# Verify .env has APP_KEY
grep APP_KEY .env
```

---

### Issue: Session Expiring Too Quickly

**Symptoms:** Users logged out after a few minutes

**Solutions:**

1. **Increase session lifetime in `.env`:**
```env
SESSION_LIFETIME=120
```

2. **Check session driver:**
```env
SESSION_DRIVER=file
# or for better performance
SESSION_DRIVER=redis
```

3. **Clear sessions:**
```bash
php artisan session:clear
```

---

## 💾 Installation Problems

### Problem: Composer Install Fails

**Error:** "Your requirements could not be resolved"

**Solutions:**

```bash
# Update composer
composer self-update

# Clear composer cache
composer clear-cache

# Install with verbose output
composer install --no-dev --optimize-autoloader -vvv

# Increase memory limit
php -d memory_limit=512M composer install
```

---

### Problem: npm install Fails

**Error:** Various npm errors

**Solutions:**

```bash
# Clear npm cache
npm cache clean --force

# Delete node_modules and package-lock
rm -rf node_modules package-lock.json

# Reinstall
npm install

# Use legacy peer deps if needed
npm install --legacy-peer-deps
```

---

### Problem: Migration Fails

**Error:** "SQLSTATE[42S01]: Base table or view already exists"

**Solutions:**

```bash
# Check migration status
php artisan migrate:status

# Rollback and remigrate
php artisan migrate:rollback
php artisan migrate

# Fresh migration (WARNING: deletes all data)
php artisan migrate:fresh

# Check for duplicate migrations
ls -la database/migrations/
```

---

## 🗄️ Database Issues

### Issue: "Too many connections"

**Symptoms:** Database connection errors under load

**Solutions:**

1. **Increase MySQL max_connections:**
```sql
SET GLOBAL max_connections = 200;
```

2. **Add to my.cnf:**
```ini
[mysqld]
max_connections = 200
wait_timeout = 600
```

3. **Optimize connection pooling:**
```env
DB_CONNECTION_POOL=true
```

---

### Issue: Slow Query Performance

**Symptoms:** Pages loading slowly, timeouts

**Solutions:**

```bash
# 1. Enable query logging
# In .env
DB_LOG_QUERIES=true

# 2. Check slow queries
tail -f storage/logs/laravel.log | grep "Query took"

# 3. Add database indexes
# Check documentation for recommended indexes

# 4. Optimize tables
mysql -u root -p
USE your_database;
OPTIMIZE TABLE stock_items;
OPTIMIZE TABLE sale_records;
OPTIMIZE TABLE purchase_orders;
```

---

### Issue: Data Isolation Not Working (Multi-Tenant)

**Symptoms:** Users seeing data from other companies

**Causes:**
1. CompanyScope not applied
2. Queries bypassing global scope

**Solutions:**

```php
// Check if model has CompanyScope
// In app/Models/YourModel.php
protected static function booted(): void
{
    static::addGlobalScope(new CompanyScope);
}

// When you need to bypass (admin only)
$items = StockItem::withoutGlobalScope(CompanyScope::class)->get();
```

---

## 🚀 Performance Problems

### Issue: Slow Dashboard Loading

**Solutions:**

```bash
# 1. Enable caching
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 2. Install Redis for cache
# In .env
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# 3. Enable OPcache
# In php.ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
```

---

### Issue: Large Database Slowing Down

**Solutions:**

```sql
-- 1. Archive old data
-- Move old sales to archive table
CREATE TABLE sale_records_archive LIKE sale_records;
INSERT INTO sale_records_archive 
SELECT * FROM sale_records WHERE sale_date < DATE_SUB(NOW(), INTERVAL 1 YEAR);

-- 2. Add indexes
CREATE INDEX idx_sale_date ON sale_records(sale_date);
CREATE INDEX idx_company_id ON sale_records(company_id);
CREATE INDEX idx_stock_item ON sale_records(stock_item_id);

-- 3. Optimize tables
OPTIMIZE TABLE sale_records;
ANALYZE TABLE sale_records;
```

---

## 📦 Sales & Stock Issues

### Issue: Stock Not Deducting on Sale

**Symptoms:** Stock quantity remains same after sale

**Common Causes:**
1. Sale status not "Completed"
2. Stock deduction disabled
3. Model events not firing

**Solutions:**

```bash
# 1. Check sale record status
# In database
SELECT id, status, quantity FROM sale_records WHERE id = X;

# 2. Check stock item
SELECT id, current_quantity FROM stock_items WHERE id = Y;

# 3. Force stock update
php artisan tinker
>>> $sale = App\Models\SaleRecord::find(X);
>>> $sale->updateStockQuantity();
```

---

### Issue: Negative Stock Allowed

**Symptoms:** Stock quantity goes below zero

**Solutions:**

```php
// Add validation in SaleRecord model
protected static function boot()
{
    parent::boot();
    
    static::creating(function ($sale) {
        $stock = StockItem::find($sale->stock_item_id);
        if ($stock && $stock->current_quantity < $sale->quantity) {
            throw new \Exception("Insufficient stock. Available: {$stock->current_quantity}");
        }
    });
}
```

---

### Issue: Incorrect Financial Reports

**Symptoms:** Reports showing wrong totals

**Solutions:**

```bash
# 1. Regenerate financial report
php artisan tinker
>>> $report = App\Models\FinancialReport::find(X);
>>> $report->do_generate = 'Yes';
>>> $report->save();
>>> App\Services\FinancialReportService::generate($report);

# 2. Check financial period dates
# Ensure dates are correct in database

# 3. Verify data integrity
php artisan app:verify-financial-data
```

---

## 🏢 Multi-Tenant Issues

### Issue: Users Can't Switch Companies

**Symptoms:** Stuck in one company

**Solutions:**

```bash
# Check user company assignment
mysql -u root -p
SELECT id, name, email, company_id FROM admin_users WHERE email = 'user@example.com';

# Update if needed
UPDATE admin_users SET company_id = X WHERE id = Y;

# Clear user cache
php artisan cache:forget users.*
```

---

### Issue: Wrong Company Data Showing

**Symptoms:** Seeing another company's data

**Solutions:**

```bash
# 1. Check session company
php artisan tinker
>>> session()->get('company_id');

# 2. Verify CompanyScope is working
>>> App\Models\StockItem::count(); // Should only show current company

# 3. Clear all caches
php artisan cache:clear
php artisan config:clear

# 4. Check database for data leaks
SELECT company_id, COUNT(*) 
FROM sale_records 
GROUP BY company_id;
```

---

## 🔌 API Problems

### Issue: API Returns 401 Unauthorized

**Symptoms:** All API calls fail with 401

**Solutions:**

```bash
# 1. Check API token
curl -H "Authorization: Bearer YOUR_TOKEN" \
     https://yourdomain.com/api/stock-items

# 2. Generate new token
php artisan tinker
>>> $user = App\Models\User::find(1);
>>> $token = $user->createToken('api-token')->plainTextToken;
>>> echo $token;

# 3. Check sanctum configuration
php artisan config:clear
```

---

### Issue: CORS Errors

**Symptoms:** "blocked by CORS policy" in browser

**Solutions:**

```php
// In config/cors.php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'], // Or specific domains
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
```

---

## 📧 Email Issues

### Issue: Emails Not Sending

**Symptoms:** No emails received

**Solutions:**

```bash
# 1. Test email configuration
php artisan tinker
>>> Mail::raw('Test email', function($msg) {
    $msg->to('test@example.com')->subject('Test');
});

# 2. Check mail logs
tail -f storage/logs/laravel.log | grep Mail

# 3. Verify SMTP settings in .env
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_username
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=tls

# 4. Try queue
php artisan queue:work
```

---

### Issue: Emails Going to Spam

**Solutions:**

1. **Configure SPF record:**
```
v=spf1 include:_spf.google.com ~all
```

2. **Add DKIM signature**
3. **Set proper From address**
4. **Use authenticated SMTP**
5. **Avoid spam trigger words**

---

## ❓ Frequently Asked Questions

### Q: Can I use Budget Pro for multiple businesses?

**A:** Yes! Budget Pro is multi-tenant. Each company has completely isolated data. You can:
- **Regular License:** One installation, manage multiple companies
- **Extended License:** Multiple installations, unlimited companies

---

### Q: How do I backup my data?

**A:** Multiple options:

```bash
# 1. Database backup
mysqldump -u username -p database_name > backup.sql

# 2. Use Laravel backup package
composer require spatie/laravel-backup
php artisan backup:run

# 3. Full application backup
tar -czf budget-pro-backup.tar.gz /var/www/html/budget-pro
```

---

### Q: Can I customize the design/layout?

**A:** Yes! You can customize:
- Views: `resources/views/`
- CSS: `public/css/`
- JavaScript: `public/js/`
- Logo: Upload in company settings
- Colors: Modify CSS variables

---

### Q: How do I add more users?

**A:**

1. **Via Admin Panel:**
   - Go to Settings → Users
   - Click "New"
   - Fill in details and assign role

2. **Via Command Line:**
```bash
php artisan tinker
>>> $user = new App\Models\User();
>>> $user->first_name = 'John';
>>> $user->last_name = 'Doe';
>>> $user->email = 'john@example.com';
>>> $user->password = bcrypt('password');
>>> $user->company_id = 1;
>>> $user->save();
>>> $user->roles()->attach(2); // Role ID
```

---

### Q: What's the difference between sale records and invoices?

**A:**
- **Sale Records:** Quick POS-style sales
- **Invoices:** Can be generated from sale records as PDF documents
- Both update stock automatically

---

### Q: Can I import existing data?

**A:** Yes! You can:

1. Use demo seeder as template
2. Create custom seeder for your data
3. Use CSV import (if installed)
4. Direct database import (with caution)

```bash
# Run demo seeder to see format
php artisan db:seed --class=CompleteDemoSeeder
```

---

### Q: How do I upgrade to a new version?

**A:** See [INSTALLATION_GUIDE.md](INSTALLATION_GUIDE.md#updating-budget-pro) for detailed upgrade instructions.

Quick version:
```bash
php artisan down
# Update files
composer install --no-dev
php artisan migrate --force
php artisan cache:clear
php artisan config:cache
php artisan up
```

---

### Q: Can I run this on shared hosting?

**A:** Yes, but requires:
- PHP 8.1+ with required extensions
- SSH access (recommended)
- Composer access
- MySQL database
- Sufficient memory (512MB+)

Some shared hosts may have limitations. VPS recommended for best performance.

---

### Q: How do I reset admin password?

**A:**

```bash
php artisan tinker
>>> $user = App\Models\User::where('email', 'admin@admin.com')->first();
>>> $user->password = bcrypt('newpassword');
>>> $user->save();
>>> exit
```

---

### Q: Stock quantity not matching reports?

**A:** Run data verification:

```bash
php artisan tinker
>>> // Check stock item
>>> $item = App\Models\StockItem::find(X);
>>> echo "Current: {$item->current_quantity}\n";

>>> // Check all sales for this item
>>> $sales = App\Models\SaleRecord::where('stock_item_id', X)
     ->where('status', 'Completed')
     ->sum('quantity');
>>> echo "Sold: {$sales}\n";

>>> // Should match: Original - Sold = Current
```

---

### Q: How do I enable debug mode safely?

**A:**

```bash
# Only enable for specific IPs
# In app/Http/Middleware/TrustProxies.php
if (in_array(request()->ip(), ['YOUR_IP_ADDRESS'])) {
    config(['app.debug' => true]);
}
```

---

### Q: Can I use different currencies for different companies?

**A:** Yes! Each company can set its own currency in Settings → Company Settings.

---

### Q: How do I handle refunds?

**A:** Create a negative sale record or:

```bash
php artisan tinker
>>> $refund = new App\Models\SaleRecord();
>>> $refund->company_id = 1;
>>> $refund->stock_item_id = X;
>>> $refund->quantity = -5; // Negative for return
>>> $refund->total_amount = -500;
>>> $refund->status = 'Refunded';
>>> $refund->save();
```

---

## 🆘 Getting Additional Help

### Support Channels

**Email Support:**
- General: support@budgetpro.com
- Technical: tech@budgetpro.com
- Sales: sales@budgetpro.com

**Response Times:**
- Critical: 4 hours
- High: 12 hours
- Normal: 24 hours
- Low: 48 hours

**Documentation:**
- Full Docs: https://docs.budgetpro.com
- API Docs: https://api.budgetpro.com
- Video Tutorials: https://youtube.com/budgetpro

**Community:**
- Forum: https://community.budgetpro.com
- Discord: https://discord.gg/budgetpro

### Before Contacting Support

Please provide:
1. **Version:** Check version in footer or composer.json
2. **Error Message:** Full error from logs
3. **Steps to Reproduce:** What led to the issue
4. **Server Environment:** PHP version, MySQL version, OS
5. **Screenshots:** If UI-related

```bash
# Generate system info
php artisan about > system-info.txt
```

### Emergency Support (Premium)

For critical production issues:
- Email: emergency@budgetpro.com
- Phone: [Support Hotline]
- Available: 24/7 for Premium customers

---

## 🔍 Diagnostic Commands

```bash
# Check system health
php artisan about

# Test database connection
php artisan tinker
>>> DB::connection()->getPdo();

# Check queue status
php artisan queue:work --once

# View real-time logs
tail -f storage/logs/laravel.log

# Check disk space
df -h

# Check memory
free -m

# Test email
php artisan tinker
>>> Mail::raw('Test', function($m) { $m->to('test@example.com'); });
```

---

## 📝 Useful Log Locations

```bash
# Laravel logs
storage/logs/laravel.log

# Apache logs
/var/log/apache2/error.log

# Nginx logs
/var/log/nginx/error.log

# MySQL logs
/var/log/mysql/error.log

# PHP-FPM logs
/var/log/php8.2-fpm.log
```

---

**Last Updated:** December 9, 2025  
**Version:** 2.0.0  
**Need more help?** Contact support@budgetpro.com
