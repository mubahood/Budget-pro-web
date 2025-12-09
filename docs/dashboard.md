# Dashboard Overview

- [Introduction](#introduction)
- [Dashboard Layout](#dashboard-layout)
- [Key Metrics](#key-metrics)
- [Quick Actions](#quick-actions)
- [Widgets](#widgets)
- [Customization](#customization)
- [Filtering Data](#filtering-data)

## Introduction

The Dashboard is your command center in Budget Pro. It provides a real-time overview of your business performance, key metrics, and quick access to important features.

Upon logging in, you'll see the dashboard tailored to your company's data, showing today's performance and recent activities.

## Dashboard Layout

The dashboard is organized into several sections:

### Top Navigation Bar

The top bar contains:

- **Company Switcher**: Switch between companies (if you have access to multiple)
- **Search Bar**: Global search across all records
- **Notifications**: System alerts and reminders
- **User Menu**: Profile, settings, logout

### Sidebar Menu

The left sidebar provides navigation to all features:

```
📊 Dashboard
📦 Inventory
   ├── Stock Items
   ├── Categories
   ├── Subcategories
   └── Stock Records
💰 Sales
   ├── New Sale
   ├── Sale Records
   └── Customers
🛒 Purchases
   ├── Purchase Orders
   └── Suppliers
💵 Financial
   ├── Financial Periods
   ├── Income
   ├── Expenses
   └── Account Categories
📈 Reports
   ├── Stock Reports
   ├── Sales Reports
   └── Financial Reports
⚙️ Settings
   ├── Company Settings
   ├── Users
   └── Roles
```

### Main Content Area

The central area displays:

1. **Welcome Message**: Personalized greeting with current date
2. **Key Metrics Cards**: 4-6 important statistics
3. **Charts & Graphs**: Visual representations of data
4. **Recent Activity**: Latest transactions and changes
5. **Quick Links**: Shortcuts to common actions

## Key Metrics

The dashboard displays these important metrics:

### Total Sales Today

```
┌─────────────────────┐
│ 💰 Today's Sales    │
│ UGX 1,234,500       │
│ ↑ 15% vs yesterday  │
└─────────────────────┘
```

**What it shows:** Total revenue from completed sales today
**Click to:** View today's sale records

### Stock Value

```
┌─────────────────────┐
│ 📦 Stock Value      │
│ UGX 8,456,000       │
│ 234 items           │
└─────────────────────┘
```

**What it shows:** Total value of current inventory
**Click to:** View stock items list

### Low Stock Items

```
┌─────────────────────┐
│ ⚠️ Low Stock        │
│ 12 items            │
│ Action needed       │
└─────────────────────┘
```

**What it shows:** Number of items below reorder level
**Click to:** View items needing reorder

### This Month's Profit

```
┌─────────────────────┐
│ 📈 Monthly Profit   │
│ UGX 2,345,000       │
│ Margin: 28.5%       │
└─────────────────────┘
```

**What it shows:** Profit for current month
**Click to:** View detailed financial report

### Pending Approvals

```
┌─────────────────────┐
│ ⏳ Pending POs      │
│ 3 orders            │
│ Awaiting approval   │
└─────────────────────┘
```

**What it shows:** Purchase orders needing approval
**Click to:** Review and approve orders

### Active Users

```
┌─────────────────────┐
│ 👥 Active Users     │
│ 5 users             │
│ 2 online now        │
└─────────────────────┘
```

**What it shows:** Total users and current online count
**Click to:** Manage users

## Quick Actions

The dashboard provides quick access buttons:

### Primary Actions

**New Sale** (Green button)
- Quickly process a sale
- Opens sales form
- Keyboard shortcut: `Ctrl/Cmd + N`

**Add Stock** (Blue button)
- Add new stock item
- Opens stock item form
- Quick inventory addition

**Create PO** (Orange button)
- Create purchase order
- Opens PO form
- For procurement needs

**Generate Report** (Purple button)
- Quick report access
- Choose report type
- Download or view

### Secondary Actions

- **Import Data**: Bulk import from CSV/Excel
- **Export Records**: Export data to various formats
- **Settings**: Quick access to company settings
- **Help**: Open documentation or support

## Widgets

### Sales Chart

**Line/Bar Chart** showing:
- Daily sales for last 30 days
- Comparison with previous period
- Trend indicators
- Peak sales days highlighted

**Features:**
- Hover for detailed amounts
- Click data point to see transactions
- Toggle between daily/weekly/monthly views
- Export chart as image

### Top Selling Products

**Table/List showing:**

| Product | Quantity Sold | Revenue | Profit |
|---------|---------------|---------|--------|
| Laptop HP 15 | 12 units | 18M | 2.4M |
| Mouse Wireless | 45 units | 2.25M | 900K |
| USB Cable | 78 units | 780K | 390K |

**Features:**
- Shows top 10 by default
- Click product to view details
- View more to see full list
- Period filter (today/week/month)

### Recent Sales

**Transaction list showing:**

```
[Today, 2:45 PM] Sale #1234
Customer: John Doe
Amount: UGX 45,000
Status: Completed

[Today, 1:30 PM] Sale #1233
Customer: Jane Smith
Amount: UGX 128,000
Status: Completed

[Today, 11:15 AM] Sale #1232
Customer: Walk-in Customer
Amount: UGX 15,000
Status: Completed
```

**Features:**
- Latest 10 transactions
- Real-time updates
- Click to view full details
- Filter by status

### Stock Alerts

**Alert list showing:**

```
⚠️ Low Stock Alert
- Printer Paper A4 (5 reams left, reorder at 10)
- Toner Cartridge (2 units left, reorder at 5)
- USB Flash Drive 32GB (3 units left, reorder at 10)

❌ Out of Stock
- HDMI Cable 2m (0 units)
- Keyboard Mechanical (0 units)
```

**Features:**
- Color-coded alerts (Yellow: Low, Red: Out)
- Click to reorder
- Set reorder levels
- Dismiss alerts

### Financial Summary

**Quick financial overview:**

```
Income This Month:   UGX 12,500,000
Expenses This Month: UGX 8,200,000
Net Profit:          UGX 4,300,000
Profit Margin:       34.4%
```

**Features:**
- Current financial period
- Compared to last period
- Trend indicators
- Click for detailed report

## Customization

### Widget Management

**Customize your dashboard:**

1. **Show/Hide Widgets**
   - Click "Customize Dashboard" button
   - Toggle widgets on/off
   - Rearrange widget order
   - Save layout

2. **Widget Settings**
   - Configure data ranges
   - Set refresh intervals
   - Choose chart types
   - Adjust color schemes

3. **Layouts**
   - Default Layout: All widgets
   - Sales Focus: Sales metrics prominent
   - Inventory Focus: Stock widgets emphasized
   - Financial Focus: Financial metrics highlighted
   - Custom: Your personalized layout

### Preferences

**Dashboard preferences:**

```php
Settings → User Preferences → Dashboard

□ Auto-refresh enabled (every 5 minutes)
□ Show animations
□ Compact view
□ Dark mode
□ Show keyboard shortcuts
```

### Data Ranges

**Set default time ranges:**

- Today
- Last 7 days
- Last 30 days
- This Month
- Last Month
- This Quarter
- This Year
- Custom Range

## Filtering Data

### Company Filter

If you manage multiple companies:

```
Company: [Select Company ▼]
├── TechStore Electronics
├── Fashion Hub Boutique
└── MediCare Pharmacy
```

**Behavior:**
- All widgets update automatically
- Saved in session
- Persists until changed

### Date Range Filter

```
Date Range: [Last 30 Days ▼]
├── Today
├── Yesterday
├── Last 7 Days
├── Last 30 Days
├── This Month
├── Last Month
├── Custom Range...
```

**Affects:**
- Sales metrics
- Charts
- Recent activity
- Top products

### Status Filters

**Filter by status:**

- Active Items Only
- Include Inactive
- Completed Sales Only
- All Statuses

### Quick Filters

**One-click filters:**

- 🔴 Urgent Actions
- ⚠️ Needs Attention
- ✅ All Good
- 📊 Show All

## Dashboard Shortcuts

### Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `Ctrl/Cmd + N` | New Sale |
| `Ctrl/Cmd + K` | Global Search |
| `Ctrl/Cmd + R` | Refresh Dashboard |
| `Ctrl/Cmd + /` | Show Shortcuts |
| `G then D` | Go to Dashboard |
| `G then S` | Go to Sales |
| `G then I` | Go to Inventory |

### URL Access

Direct access URLs:

```
/admin                  - Dashboard
/admin/sales/new        - New Sale
/admin/stock-items      - Stock Items
/admin/reports          - Reports
```

## Mobile View

The dashboard is fully responsive:

**Mobile Features:**
- Swipe between widgets
- Collapsible sections
- Touch-optimized buttons
- Simplified charts
- Pull-to-refresh

**Mobile Layout:**
- Single column
- Stacked widgets
- Hamburger menu
- Bottom navigation

## Tips & Best Practices

### Daily Routine

**Start your day with:**

1. Check Today's Sales metric
2. Review Low Stock alerts
3. Approve pending POs
4. Check recent activity

### Weekly Review

**Weekly tasks:**

1. Review Sales Chart trends
2. Check Top Selling Products
3. Analyze profit margins
4. Plan inventory purchases

### Performance

**Dashboard loads faster when:**

- Cache is enabled
- Date ranges are reasonable
- Unnecessary widgets are hidden
- Data is regularly archived

### Customization Tips

**Optimize your dashboard:**

- Show only relevant widgets
- Set appropriate refresh intervals
- Use compact view for more data
- Enable keyboard shortcuts

## Troubleshooting

### Dashboard Not Loading

**Common issues:**

1. **Cache Issue**
   ```bash
   php artisan cache:clear
   php artisan config:clear
   ```

2. **Session Expired**
   - Logout and login again
   - Clear browser cache

3. **Permission Issue**
   - Check user role has dashboard access
   - Contact administrator

### Metrics Not Updating

**Solutions:**

1. **Manual Refresh**
   - Click refresh button
   - Press `Ctrl/Cmd + R`

2. **Check Filters**
   - Verify company selected
   - Check date range
   - Reset filters

3. **Cache Refresh**
   - Wait for auto-refresh (if enabled)
   - Clear application cache

### Widgets Missing

**If widgets don't appear:**

1. Check widget visibility settings
2. Verify user permissions
3. Check browser console for errors
4. Try different browser

## Next Steps

Now that you understand the dashboard:

- **[Company Settings](/docs/company-settings.md)** - Configure your company
- **[Processing Sales](/docs/processing-sales.md)** - Start selling
- **[Stock Items](/docs/stock-items.md)** - Manage inventory
- **[Reports](/docs/reports-overview.md)** - Generate insights

---

> **Tip**: Customize your dashboard to show the metrics most important to your business. Different roles might benefit from different layouts.
