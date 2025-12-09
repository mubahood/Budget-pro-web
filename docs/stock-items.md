# Stock Items Management

- [Overview](#overview)
- [Viewing Stock Items](#viewing-stock-items)
- [Adding Stock Items](#adding-stock-items)
- [Editing Stock Items](#editing-stock-items)
- [Stock Item Details](#stock-item-details)
- [Import/Export](#importexport)
- [Best Practices](#best-practices)

## Overview

Stock Items are the products you sell in your business. Budget Pro provides comprehensive inventory management with real-time tracking, categorization, and automated stock updates.

### Key Features

- **Real-time Stock Tracking**: Automatic quantity updates on sales
- **SKU & Barcode Support**: Unique identification for each product
- **Category Organization**: Hierarchical category and subcategory system
- **Reorder Alerts**: Get notified when stock runs low
- **Pricing Management**: Track buying and selling prices
- **Photo Support**: Add product images
- **Multi-Company**: Separate inventory per company

## Viewing Stock Items

### Accessing Stock Items

Navigate to: **Inventory → Stock Items**

### Grid View

The stock items grid displays:

| Column | Description |
|--------|-------------|
| **Photo** | Product image thumbnail |
| **Name** | Product name (clickable) |
| **SKU** | Stock Keeping Unit |
| **Category** | Product category |
| **Buying Price** | Cost price |
| **Selling Price** | Retail price |
| **Current Qty** | Stock on hand |
| **Reorder Level** | Minimum stock threshold |
| **Status** | Active/Inactive |
| **Actions** | View/Edit/Delete |

### Filters

Use filters to find items quickly:

**Basic Filters:**
```
🔍 Search: Product name, SKU, or barcode
📁 Category: Filter by category
📂 Subcategory: Filter by subcategory
🟢 Status: Active/Inactive/All
⚠️ Stock Level: All/Low Stock/Out of Stock
```

**Advanced Filters:**
```
💰 Price Range: Min-Max selling price
📊 Quantity Range: Min-Max stock levels
📅 Date Added: Created date range
👤 Created By: Filter by user
```

**Example Filter Combinations:**

*Find low stock electronics:*
```
Category: Electronics
Stock Level: Low Stock
Status: Active
```

*Find expensive items:*
```
Price Range: 1,000,000 - 10,000,000
Status: Active
Sort: Selling Price (High to Low)
```

### Sorting

Click column headers to sort:

- **Name**: A-Z or Z-A
- **Selling Price**: Low to High or High to Low
- **Current Qty**: Low to High or High to Low
- **Created At**: Newest or Oldest

### Quick Actions

From the grid, you can:

- 👁️ **View**: See full details
- ✏️ **Edit**: Modify item
- 🗑️ **Delete**: Remove item (soft delete)
- 📄 **Clone**: Duplicate item
- 📊 **Stock History**: View movements
- 📈 **Sales Report**: Item performance

## Adding Stock Items

### Step 1: Open Form

Click **New** button in Stock Items list

### Step 2: Basic Information

**Required Fields:**

```yaml
Product Name: "HP Pavilion 15 Laptop"
  - Clear, descriptive name
  - Customer-facing description
  - Example: "Brand Model Specification"

SKU: "HP-PAV-15-001"
  - Unique identifier
  - Recommendation: Brand-Model-Variant-Number
  - Auto-generated if left empty

Category: "Electronics"
  - Main product category
  - Must exist first
  - Create in Inventory → Categories

Subcategory: "Computers"
  - Secondary classification
  - Optional but recommended
  - Create in Inventory → Subcategories
```

### Step 3: Pricing

```yaml
Buying Price: 2,500,000 UGX
  - Your cost price
  - What you paid supplier
  - Used for profit calculations

Selling Price: 3,200,000 UGX
  - Customer price
  - What you charge
  - Must be ≥ Buying Price
  
💡 Profit per Unit: 700,000 UGX (28% margin)
   Auto-calculated and displayed
```

**Pricing Tips:**

*Calculate markup:*
```
Markup % = ((Selling - Buying) / Buying) × 100
Example: ((3,200,000 - 2,500,000) / 2,500,000) × 100 = 28%
```

*Calculate margin:*
```
Margin % = ((Selling - Buying) / Selling) × 100
Example: ((3,200,000 - 2,500,000) / 3,200,000) × 100 = 21.9%
```

### Step 4: Stock Information

```yaml
Current Quantity: 10
  - Initial stock level
  - Number of units available
  - Will update automatically on sales

Unit of Measure: "Unit"
  - How product is counted
  - Options: Unit, Piece, Box, Carton, Kg, Liter, etc.
  - Consistent measurement

Reorder Level: 3
  - Minimum stock threshold
  - Alert when stock falls below this
  - Plan: Reorder Level = (Daily Sales × Lead Time) + Safety Stock
  - Example: (0.5 units/day × 5 days) + 1 = 3.5 ≈ 3 units
```

**Reorder Level Calculator:**

```
Daily Average Sales: 2 units
Supplier Lead Time: 7 days
Safety Stock: 3 units (buffer)

Reorder Level = (2 × 7) + 3 = 17 units
```

### Step 5: Identification

```yaml
Barcode: "1234567890123" (Optional)
  - 13-digit EAN/UPC code
  - Or generate QR code
  - Use barcode scanner for faster sales
  
Photo: Upload (Optional)
  - Product image
  - Recommended: 512x512 pixels
  - Max size: 2MB
  - Formats: JPG, PNG, WebP
```

### Step 6: Additional Details

```yaml
Description:
  "15.6 inch Full HD display, Intel Core i5-1135G7,
   8GB DDR4 RAM, 512GB NVMe SSD, Windows 11 Pro,
   Intel Iris Xe Graphics, Backlit keyboard"
  
  - Detailed specifications
  - Customer-facing information
  - Used in invoices/receipts
  
Status: "Active"
  - Active: Available for sale
  - Inactive: Hidden from sales
```

### Step 7: Save

Click **Submit** to save the item.

**What happens after saving:**
- ✅ Item added to inventory
- ✅ SKU generated (if not provided)
- ✅ Available for immediate sale
- ✅ Appears in stock reports
- ✅ Reorder alert configured

## Editing Stock Items

### Quick Edit

From grid, click **Edit** button (pencil icon)

### What You Can Edit

**Freely Editable:**
- Name
- Description
- Photo
- Selling Price (future sales only)
- Buying Price (future purchases only)
- Reorder Level
- Status

**Edit with Caution:**
- **Current Quantity**: Use stock adjustments instead
- **Category**: May affect reporting
- **SKU**: May break external integrations

**Cannot Edit:**
- Company (fixed at creation)
- Created date
- Historical transactions

### Bulk Edit

Edit multiple items at once:

1. Select items (checkboxes)
2. Click **Batch Actions**
3. Choose action:
   - Update Category
   - Update Status
   - Adjust Prices (% increase/decrease)
   - Set Reorder Levels

**Example: 10% Price Increase**
```
Select: All Electronics
Action: Adjust Prices
Type: Percentage Increase
Value: 10%
Apply to: Selling Price
```

### Stock Adjustments

**Don't edit quantity directly!** Use stock adjustments:

**Inventory → Stock Records → New Stock Record**

```yaml
Stock Item: "HP Pavilion 15 Laptop"
Type: "Addition" or "Subtraction"
Quantity: 5
Reason: "New stock arrival" or "Damaged units"
Date: Today
Notes: "PO-2025-001, Invoice #12345"
```

**Benefits:**
- ✅ Audit trail maintained
- ✅ Reasons documented
- ✅ Reports accurate
- ✅ Accountability clear

## Stock Item Details

### View Details Page

Click item name or **View** button

### Information Tabs

**1. Overview Tab**

```
┌─────────────────────────────────────┐
│  📦 HP Pavilion 15 Laptop          │
│  SKU: HP-PAV-15-001                 │
│  [Product Photo]                    │
├─────────────────────────────────────┤
│  Category: Electronics > Computers  │
│  Status: 🟢 Active                  │
│  In Stock: 10 units                 │
│  Reorder Level: 3 units             │
├─────────────────────────────────────┤
│  Buying Price: 2,500,000 UGX       │
│  Selling Price: 3,200,000 UGX      │
│  Profit/Unit: 700,000 UGX (28%)    │
├─────────────────────────────────────┤
│  Description:                       │
│  15.6" FHD, i5, 8GB RAM, 512GB SSD │
└─────────────────────────────────────┘
```

**2. Sales History Tab**

Recent sales of this item:

| Date | Customer | Qty | Price | Total | Status |
|------|----------|-----|-------|-------|--------|
| 9 Dec 2025 | John Doe | 1 | 3,200,000 | 3,200,000 | Completed |
| 8 Dec 2025 | Jane Smith | 1 | 3,200,000 | 3,200,000 | Completed |
| 7 Dec 2025 | Walk-in | 1 | 3,200,000 | 3,200,000 | Completed |

**Summary:**
- Total Sold: 15 units
- Total Revenue: 48,000,000 UGX
- Average Price: 3,200,000 UGX

**3. Stock Movement Tab**

All quantity changes:

| Date | Type | Qty | Balance | Reason | By |
|------|------|-----|---------|--------|-----|
| 9 Dec | Sale | -1 | 10 | Sale #1234 | Mary |
| 8 Dec | Addition | +20 | 11 | PO received | John |
| 7 Dec | Sale | -1 | -9 | Sale #1233 | Mary |
| 6 Dec | Adjustment | -2 | 10 | Damaged | Admin |

**4. Purchase History Tab**

Procurement history:

| PO Number | Date | Supplier | Qty | Unit Price | Total |
|-----------|------|----------|-----|-----------|-------|
| PO-2025-003 | 8 Dec | Tech Dist | 20 | 2,450,000 | 49M |
| PO-2025-001 | 1 Dec | Tech Dist | 10 | 2,500,000 | 25M |

**5. Performance Tab**

Analytics and insights:

```
📊 Sales Performance (Last 30 Days)

Total Units Sold: 15
Total Revenue: 48,000,000 UGX
Total Profit: 10,500,000 UGX
Average Daily Sales: 0.5 units

📈 Trends:
- Best Day: Monday (5 units)
- Peak Time: 2-4 PM
- Best Customer: Corporate (8 units)

⚠️ Alerts:
- Stock running low (10 units, reorder at 3)
- Price competitive (market avg: 3,100,000)
- Popular item (Top 5 this month)
```

## Import/Export

### Import Stock Items

**Step 1: Download Template**

Click **Import** button → **Download Template**

Gets you: `stock_items_template.xlsx`

**Step 2: Fill Template**

| name* | sku | category* | buying_price* | selling_price* | quantity* | reorder_level | description |
|-------|-----|-----------|---------------|----------------|-----------|---------------|-------------|
| Laptop HP | HP-001 | Electronics | 2500000 | 3200000 | 10 | 3 | Core i5, 8GB |
| Mouse | MS-001 | Accessories | 35000 | 50000 | 50 | 10 | Wireless |

*Required fields

**Step 3: Upload**

1. Click **Import**
2. Select file
3. Map columns (if different)
4. Validate data
5. Import

**Validation Checks:**
- ✅ Required fields present
- ✅ Prices are numbers
- ✅ Categories exist
- ✅ SKUs are unique
- ✅ No duplicate names (warning)

**Result:**
```
✅ Successfully imported: 45 items
⚠️ Warnings: 3 items (duplicate SKUs updated)
❌ Errors: 2 items (invalid categories)

Download error report for details
```

### Export Stock Items

**Export Options:**

1. **Current View**
   - Exports filtered results
   - Same columns as shown
   - Respects sorting

2. **All Data**
   - All stock items
   - All fields
   - Complete database export

3. **Custom Export**
   - Choose fields
   - Apply filters
   - Select format

**Formats:**
- CSV (Excel-compatible)
- Excel (.xlsx)
- PDF (printable report)
- JSON (for integrations)

**Example Export:**

```
Click: Export → Excel → Selected Columns

Choose fields:
☑️ Name
☑️ SKU
☑️ Category
☑️ Current Quantity
☑️ Selling Price
☑️ Reorder Level
☐ Description (unchecked)
☐ Photo (unchecked)

Filter: Category = Electronics, Status = Active

Result: electronics_stock_2025-12-09.xlsx
```

## Best Practices

### SKU Naming Conventions

**Good SKU Format:**
```
[Brand]-[Category]-[Variant]-[Number]

Examples:
HP-LAP-15-001    (HP Laptop 15" #001)
APP-PHO-13-BLK   (Apple iPhone 13 Black)
SAM-TV-55-4K     (Samsung TV 55" 4K)
```

**Benefits:**
- Easy to search
- Logical grouping
- Scalable system
- Human-readable

### Category Organization

**Create Hierarchical Structure:**

```
Electronics (Parent)
├── Computers (Child)
│   ├── Laptops (Grandchild via subcategory)
│   ├── Desktops
│   └── Tablets
├── Phones
│   ├── Smartphones
│   └── Feature Phones
└── Accessories
    ├── Cables
    ├── Cases
    └── Chargers
```

**Tips:**
- Maximum 2-3 levels
- 5-10 items per category
- Consistent naming
- No overlapping categories

### Pricing Strategy

**Cost-Plus Pricing:**
```
Selling Price = (Buying Price × (1 + Markup%))

Example 30% markup:
Buying: 100,000 UGX
Selling: 100,000 × 1.30 = 130,000 UGX
```

**Competitive Pricing:**
```
Research market prices
Set competitive price
Ensure minimum margin (15-20%)
```

**Psychological Pricing:**
```
Instead of: 100,000 UGX
Use: 99,900 UGX

Instead of: 3,200,000 UGX
Use: 3,199,000 UGX
```

### Stock Level Management

**Reorder Point Formula:**
```
Reorder Point = (Average Daily Usage × Lead Time) + Safety Stock

Example:
Daily Usage: 2 units/day
Lead Time: 7 days
Safety Stock: 5 units

Reorder Point = (2 × 7) + 5 = 19 units
```

**Safety Stock:**
```
Safety Stock = (Max Daily Usage - Avg Daily Usage) × Lead Time

Example:
Max Daily: 5 units
Avg Daily: 2 units
Lead Time: 7 days

Safety Stock = (5 - 2) × 7 = 21 units
```

### Regular Maintenance

**Daily Tasks:**
- Check low stock alerts
- Update stock from sales
- Verify stock movements
- Process new arrivals

**Weekly Tasks:**
- Review slow-moving items
- Check pricing competitiveness
- Update reorder levels
- Clean up inactive items

**Monthly Tasks:**
- Physical stock count
- Reconcile discrepancies
- Archive old data
- Update categories

### Data Quality

**Maintain Clean Data:**

✅ **Do:**
- Use consistent naming
- Complete all fields
- Add clear descriptions
- Upload quality photos
- Document changes
- Regular audits

❌ **Don't:**
- Duplicate items
- Use vague names
- Skip descriptions
- Ignore categories
- Manual quantity edits
- Delete sold items

## Troubleshooting

### Common Issues

**Issue: SKU already exists**
```
Solution:
- Each SKU must be unique
- Check existing items
- Use different SKU
- Or update existing item
```

**Issue: Can't find item in sales**
```
Solution:
- Check item status (must be Active)
- Verify company filter
- Check stock quantity > 0
- Refresh page
```

**Issue: Stock not deducting**
```
Solution:
- Sale status must be "Completed"
- Check permissions
- Review stock records
- Contact support if persists
```

**Issue: Import failing**
```
Solution:
- Check template format
- Verify required fields
- Check categories exist
- Review error report
- Fix data and retry
```

## Next Steps

- **[Stock Categories](/docs/stock-categories.md)** - Organize inventory
- **[Processing Sales](/docs/processing-sales.md)** - Sell products
- **[Stock Records](/docs/stock-records.md)** - Track movements
- **[Reorder Management](/docs/reorder-management.md)** - Automate purchasing

---

> **Pro Tip**: Use barcode scanners for faster inventory management. Budget Pro supports standard barcode formats and can generate codes for your products.
