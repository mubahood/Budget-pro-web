# Budget Pro - Quick Start Guide

**Version:** 2.0.0  
**For:** New Users & Customers  
**Updated:** December 9, 2025

---

## ⚡ 5-Minute Quick Start

### 1. Installation (Choose One)

**Option A: Quick Install (Recommended)**
```bash
cd /var/www/html
git clone YOUR_REPO_URL budget-pro
cd budget-pro
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
# Edit .env with your database credentials
php artisan migrate --seed
```

**Option B: One Command**
```bash
curl -s https://yourdomain.com/install.sh | bash
```

### 2. First Login

**URL:** http://yourdomain.com/admin  
**Email:** admin@admin.com  
**Password:** admin

⚠️ **Change password immediately after first login!**

### 3. Initial Setup (3 Steps)

**Step 1: Company Settings**
- Go to Settings → Company Settings
- Fill in your company details
- Upload logo
- Set currency

**Step 2: Create Categories**
- Go to Inventory → Categories
- Add your product categories
- Add subcategories

**Step 3: Add First Product**
- Go to Inventory → Stock Items
- Click "New"
- Fill in product details
- Save

---

## 📚 Essential Features

### Inventory Management
```
Inventory → Stock Items
- Add/Edit/Delete products
- Track quantities
- Set reorder levels
- Manage SKUs & barcodes
```

### Sales Processing
```
Sales → New Sale
- Select product
- Enter quantity
- Add customer details
- Process payment
```

### Financial Reports
```
Reports → Financial Reports
- Select period
- Generate report
- View profit/loss
- Export PDF
```

### Multi-Company
```
Settings → Companies
- Add new company
- Assign owner
- Switch between companies
```

---

## 🔧 Common Tasks

### Add New User
```
Settings → Users → New
- Fill in details
- Assign role (Owner/Manager/Staff)
- Set company
- Save
```

### Generate Invoice
```
Sales → Sale Records → View
- Click "Generate PDF"
- Download invoice
- Send to customer
```

### Check Stock Levels
```
Inventory → Stock Items
- Use "Low Stock" filter
- View items below reorder level
- Create purchase orders
```

### Export Data
```
Any Grid → Export
- Choose format (CSV/Excel/PDF)
- Download file
```

---

## 🆘 Quick Troubleshooting

### Can't Login?
```bash
# Reset password
php artisan tinker
>>> $user = App\Models\User::where('email', 'admin@admin.com')->first();
>>> $user->password = bcrypt('newpassword');
>>> $user->save();
```

### Page Not Loading?
```bash
# Clear cache
php artisan cache:clear
php artisan config:clear
php artisan view:clear
```

### Stock Not Updating?
- Check sale status is "Completed"
- Verify stock item exists
- Check company_id matches

---

## 📖 Full Documentation

- **Installation:** [INSTALLATION_GUIDE.md](INSTALLATION_GUIDE.md)
- **User Manual:** [USER_MANUAL.md](USER_MANUAL.md) *(coming soon)*
- **API Docs:** [API_DOCUMENTATION.md](API_DOCUMENTATION.md)
- **Troubleshooting:** [TROUBLESHOOTING.md](TROUBLESHOOTING.md)
- **Developer Guide:** [DEVELOPER_GUIDE.md](DEVELOPER_GUIDE.md)
- **Deployment:** [DEPLOYMENT_GUIDE.md](DEPLOYMENT_GUIDE.md)

---

## 💬 Support

**Email:** support@budgetpro.com  
**Response Time:** 24 hours  
**Emergency:** emergency@budgetpro.com (Premium)

---

## 🎯 Next Steps

1. ✅ Complete initial setup
2. ✅ Add your products
3. ✅ Process first sale
4. ✅ Generate first report
5. ✅ Explore advanced features

**Need help?** Check [TROUBLESHOOTING.md](TROUBLESHOOTING.md) or contact support.

---

**Version:** 2.0.0  
**Last Updated:** December 9, 2025
