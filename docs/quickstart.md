# Quick Start Guide

- [Your First 5 Minutes](#your-first-5-minutes)
- [Initial Setup](#initial-setup)
- [Adding Your First Product](#adding-your-first-product)
- [Processing Your First Sale](#processing-your-first-sale)
- [Viewing Reports](#viewing-reports)
- [Next Steps](#next-steps)

## Your First 5 Minutes

Get up and running with Budget Pro in just 5 minutes! This guide assumes you've already installed the system.

### Step 1: Login (30 seconds)

1. Navigate to your Budget Pro URL: `http://yourdomain.com/admin`
2. Enter credentials:
   - **Email:** admin@admin.com
   - **Password:** admin
3. Click **Login**

> **⚠️ Important:** Change these default credentials immediately after first login!

### Step 2: Change Password (1 minute)

1. Click your name in top-right corner
2. Select **Profile**
3. Click **Change Password**
4. Enter:
   - Current Password: `admin`
   - New Password: (strong password)
   - Confirm Password: (repeat)
5. Click **Save**

### Step 3: Update Company Settings (2 minutes)

1. Go to **Settings → Company Settings**
2. Update:
   - **Company Name:** Your business name
   - **Currency:** Your local currency (default: UGX)
   - **Email:** Your business email
   - **Phone:** Your business phone
3. Click **Submit**

### Step 4: Add Your First Product (1 minute)

1. Go to **Inventory → Stock Items**
2. Click **New** button
3. Fill in:
   - **Name:** Product name (e.g., "Laptop HP 15")
   - **Category:** Select or create
   - **Selling Price:** Price customers pay
   - **Buying Price:** Cost you paid
   - **Current Quantity:** Stock on hand
4. Click **Submit**

### Step 5: Process Your First Sale (30 seconds)

1. Go to **Sales → New Sale**
2. Select:
   - **Stock Item:** Choose your product
   - **Quantity:** Number sold
3. Price auto-fills from product
4. Click **Submit**

**🎉 Congratulations!** You've just processed your first sale in Budget Pro!

## Initial Setup

For a more comprehensive setup, follow these steps:

### 1. Company Information

**Settings → Company Settings**

Complete all fields:

```yaml
Basic Information:
  Company Name: "Your Business Name"
  Phone Number: "+256-XXX-XXXXXX"
  Email: "info@yourbusiness.com"
  Address: "Your physical address"
  
Branding:
  Logo: Upload your logo (recommended: 512x512 PNG)
  Slogan: "Your business tagline"
  Currency: "UGX" or your currency
  
About:
  Description: Brief company description
```

### 2. Financial Period

**Financial → Financial Periods**

Create your first accounting period:

```yaml
Name: "2025 Q1"
Start Date: "2025-01-01"
End Date: "2025-03-31"
Status: "Active"
Description: "First quarter 2025"
```

> **Note:** Only one period can be active at a time. All transactions will be recorded in the active period.

### 3. User Accounts

**Settings → Users**

Add your team members:

**Example - Sales Manager:**
```yaml
First Name: "John"
Last Name: "Doe"
Email: "john@yourbusiness.com"
Password: (generate secure password)
Role: "Sales Manager"
Company: "Your Company"
```

**Example - Stock Keeper:**
```yaml
First Name: "Jane"
Last Name: "Smith"
Email: "jane@yourbusiness.com"
Password: (generate secure password)
Role: "Stock Keeper"
Company: "Your Company"
```

### 4. Stock Categories

**Inventory → Categories**

Organize your products:

**Electronics Store Example:**
```
📱 Electronics
   ├── Computers
   ├── Phones & Tablets
   ├── Accessories
   └── Software

🏠 Home Appliances
   ├── Kitchen
   ├── Cleaning
   └── Personal Care

📚 Office Supplies
   ├── Stationery
   ├── Printing
   └── Furniture
```

**Create a Category:**
```yaml
Name: "Electronics"
Description: "Electronic devices and accessories"
Status: "Active"
```

**Create Subcategories:**
```yaml
Name: "Computers"
Category: "Electronics"
Description: "Laptops, desktops, and accessories"
```

## Adding Your First Product

Let's add a complete product with all details:

### Basic Product Information

**Inventory → Stock Items → New**

**Example: Laptop Product**

```yaml
Basic Information:
  Name: "HP Pavilion 15 Laptop"
  SKU: "HP-PAV-15-001"
  Barcode: "1234567890123" (optional)
  Category: "Electronics"
  Subcategory: "Computers"
  
Pricing:
  Buying Price: 2,500,000 UGX
  Selling Price: 3,200,000 UGX
  (Profit per unit: 700,000 UGX - 28% margin)
  
Stock Information:
  Current Quantity: 10
  Unit of Measure: "Unit"
  Reorder Level: 3
  
Additional Details:
  Description: "15.6 inch, Intel Core i5, 8GB RAM, 512GB SSD"
  Photo: Upload product image
  Status: "Active"
```

Click **Submit** to save.

### Quick Add Multiple Products

Use this spreadsheet format for bulk import:

| Name | SKU | Category | Buying Price | Selling Price | Quantity |
|------|-----|----------|--------------|---------------|----------|
| Laptop HP 15 | HP-001 | Computers | 2500000 | 3200000 | 10 |
| Mouse Wireless | MS-001 | Accessories | 35000 | 50000 | 50 |
| Keyboard RGB | KB-001 | Accessories | 85000 | 120000 | 25 |

Save as CSV and import via **Inventory → Stock Items → Import**

## Processing Your First Sale

### Simple Walk-in Sale

**Sales → New Sale**

**Scenario:** Customer buys 1 laptop

```yaml
Sale Information:
  Stock Item: "HP Pavilion 15 Laptop"
  Quantity: 1
  Unit Price: 3,200,000 UGX (auto-filled)
  
Customer Information: (Optional)
  Customer Name: "Walk-in Customer"
  
Payment:
  Payment Method: "Cash"
  Status: "Completed"
```

Click **Submit**

**What happens:**
- ✅ Sale recorded
- ✅ Stock reduced from 10 to 9
- ✅ Revenue added to today's total
- ✅ Invoice generated (optional)

### Sale with Customer Details

**Better tracking with customer info:**

```yaml
Sale Information:
  Stock Item: "HP Pavilion 15 Laptop"
  Quantity: 1
  
Customer Information:
  Customer Name: "John Doe"
  Customer Phone: "+256-700-123456"
  Customer Email: "john@example.com"
  
Payment:
  Payment Method: "Bank Transfer"
  Amount Paid: 3,200,000 UGX
  Status: "Completed"
  
Notes:
  "Invoice #INV-2025-001 sent via email"
```

### Partial Payment Sale

**Customer pays in installments:**

```yaml
Sale Information:
  Stock Item: "HP Pavilion 15 Laptop"
  Quantity: 1
  Total Amount: 3,200,000 UGX
  
Payment:
  Payment Method: "Cash + Mobile Money"
  Amount Paid: 1,500,000 UGX
  Balance: 1,700,000 UGX
  Status: "Partial Payment"
  
Notes:
  "Customer will pay balance next week"
```

Stock is still reduced. Track balance in Sale Records.

## Viewing Reports

### Today's Sales Report

**Reports → Sales Reports**

```yaml
Date Range: "Today"
Filter: "All"
```

**You'll see:**

```
Today's Sales Summary
Date: 9th December 2025

Total Sales: 3,200,000 UGX
Items Sold: 1
Transactions: 1
Average Sale: 3,200,000 UGX

Top Products:
1. HP Pavilion 15 Laptop - 1 unit - 3,200,000 UGX
```

### Current Stock Report

**Reports → Stock Reports**

```yaml
Report Type: "Current Stock Levels"
Category: "All"
```

**Sample Output:**

| Product | Category | In Stock | Reorder Level | Status |
|---------|----------|----------|---------------|--------|
| HP Pavilion 15 | Computers | 9 | 3 | ✅ OK |
| Mouse Wireless | Accessories | 50 | 10 | ✅ OK |
| Keyboard RGB | Accessories | 25 | 5 | ✅ OK |

### Quick Financial Summary

**Dashboard → Monthly Profit Widget**

```
This Month (December 2025)
Sales:     3,200,000 UGX
Cost:      2,500,000 UGX
Profit:    700,000 UGX
Margin:    21.9%
```

## Next Steps

### Week 1 Goals

**Days 1-2: Setup Complete**
- [x] Login and change password
- [x] Update company settings
- [x] Create financial period
- [ ] Add all team members
- [ ] Setup all categories

**Days 3-5: Add Inventory**
- [ ] Add all current stock items
- [ ] Take photos of products
- [ ] Set reorder levels
- [ ] Print barcode labels (if using)

**Days 6-7: Start Operations**
- [ ] Process real sales
- [ ] Generate invoices
- [ ] Check daily reports
- [ ] Train staff

### Common First Week Tasks

#### Create More Categories

**Inventory → Categories**

Add all your product categories:

1. Click **New**
2. Enter category name
3. Add description
4. Save
5. Add subcategories

#### Add More Products

**Inventory → Stock Items**

Methods:
1. **Manual Entry**: Add one by one (best for few items)
2. **CSV Import**: Bulk add (best for many items)
3. **Excel Template**: Use provided template

#### Setup Suppliers

**Purchases → Suppliers**

Add your regular suppliers:

```yaml
Supplier Information:
  Name: "Tech Distributors Ltd"
  Contact Person: "David Smith"
  Phone: "+256-XXX-XXXXXX"
  Email: "orders@techdist.com"
  Address: "Industrial Area, Kampala"
  
Payment Terms: "Net 30"
Notes: "Main laptop supplier, 5% discount on bulk orders"
```

#### Create Purchase Order

**Purchases → Purchase Orders → New**

```yaml
Basic Information:
  PO Number: "PO-2025-001" (auto-generated)
  Supplier: "Tech Distributors Ltd"
  Expected Delivery: "2025-12-20"
  
Items:
  - Product: "HP Pavilion 15 Laptop"
    Quantity: 20 units
    Unit Price: 2,450,000 UGX (negotiated price)
    Total: 49,000,000 UGX
  
Total Amount: 49,000,000 UGX
Status: "Draft"
```

Save as Draft, then submit for approval.

### Learning Resources

#### Video Tutorials

Watch these short videos:

1. **5-Minute Tour** - Overview of all features
2. **Processing Sales** - Step-by-step sales guide
3. **Inventory Management** - Stock management tips
4. **Reports Explained** - Understanding reports

Access videos at: `https://youtube.com/budgetpro`

#### Documentation

Read detailed guides:

- **[Dashboard Overview](/docs/dashboard.md)** - Understand your dashboard
- **[Stock Items](/docs/stock-items.md)** - Master inventory
- **[Processing Sales](/docs/processing-sales.md)** - Sales techniques
- **[Financial Reports](/docs/financial-reports-detail.md)** - Financial analysis

#### Practice Mode

Use demo data to practice:

```bash
php artisan db:seed --class=CompleteDemoSeeder
```

This creates:
- 3 demo companies
- 300+ products
- 600+ sales
- Sample reports

Practice without affecting real data!

## Tips for Success

### Daily Habits

**Every Morning:**
1. Check dashboard for overview
2. Review low stock alerts
3. Check pending approvals
4. Prepare for the day

**Every Evening:**
1. Review today's sales
2. Reconcile cash drawer
3. Check for errors
4. Plan tomorrow's purchases

### Weekly Habits

**Every Monday:**
1. Review last week's performance
2. Plan week's goals
3. Check inventory needs
4. Schedule restocking

**Every Friday:**
1. Generate week's reports
2. Reconcile all payments
3. Backup data
4. Review with team

### Best Practices

**Inventory Management:**
- Update stock levels daily
- Set realistic reorder levels
- Use categories consistently
- Take photos of products
- Regular stock counts

**Sales Processing:**
- Always enter customer details
- Issue receipts/invoices
- Verify stock before selling
- Record partial payments
- Follow up on balances

**Financial Management:**
- Record all expenses
- Close periods on time
- Review reports weekly
- Monitor profit margins
- Plan based on data

## Common Questions

### "Can I use it without internet?"

Yes, once installed on your server, it works on your local network without internet. Internet only needed for:
- Cloud backups
- Software updates
- Email notifications
- Remote access

### "How do I backup my data?"

**Automatic Backup:**
```bash
# Runs daily at 2 AM
/usr/local/bin/backup-budget-pro.sh
```

**Manual Backup:**
```bash
# Database
mysqldump -u user -p database_name > backup.sql

# Files
tar -czf backup.tar.gz /var/www/html/budget-pro/storage
```

### "Can I use it on mobile?"

Yes! The interface is fully responsive:
- Works on phones and tablets
- Touch-optimized
- Mobile-friendly menus
- Same features as desktop

### "How many users can I have?"

Unlimited users! Add as many team members as needed:
- Owners: Full access
- Managers: Most features
- Staff: Basic operations

### "Is my data secure?"

Yes, with multiple security layers:
- Password hashing
- Role-based access
- Audit logging
- Data isolation
- Regular backups

## Get Help

### Support Channels

**Email:**
- support@budgetpro.com (24hr response)
- tech@budgetpro.com (technical issues)

**Live Chat:**
- Available on dashboard
- Mon-Fri, 9 AM - 5 PM

**Community:**
- Forum: community.budgetpro.com
- Discord: discord.gg/budgetpro

### Emergency Issues

**Critical problems:**
- Email: emergency@budgetpro.com
- Phone: [Support Hotline]
- Available 24/7 for premium customers

---

## You're Ready! 🚀

You now know enough to:
- ✅ Setup your company
- ✅ Add products
- ✅ Process sales
- ✅ View reports

**Start using Budget Pro today and grow your business!**

For detailed information on any feature, check the [full documentation](/docs/documentation.md).
