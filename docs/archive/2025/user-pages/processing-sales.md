# Processing Sales

- [Overview](#overview)
- [Quick Sale Process](#quick-sale-process)
- [Detailed Sale Entry](#detailed-sale-entry)
- [Payment Methods](#payment-methods)
- [Partial Payments](#partial-payments)
- [Sale Status](#sale-status)
- [Invoices & Receipts](#invoices--receipts)
- [Sale Modifications](#sale-modifications)
- [Best Practices](#best-practices)

## Overview

The Sales module enables quick and efficient transaction processing with automatic stock updates, customer tracking, and invoice generation.

### Key Features

- ⚡ **Fast POS Interface**: Quick sale processing
- 📦 **Automatic Stock Deduction**: Real-time inventory updates
- 👤 **Customer Management**: Track customer information
- 💳 **Multiple Payment Methods**: Cash, mobile money, bank, credit
- 📄 **Invoice Generation**: Professional PDF receipts
- 💰 **Partial Payments**: Support installment sales
- 📊 **Real-time Analytics**: Live sales tracking
- 🔄 **Multi-Company**: Separate sales per company

## Quick Sale Process

### 5-Second Sale

**For walk-in customers and quick transactions:**

**Sales → New Sale**

```yaml
1. Select Product: "HP Pavilion 15"
2. Enter Quantity: 1
3. Click Submit
```

**Done!** 
- ✅ Sale recorded
- ✅ Stock reduced
- ✅ Revenue tracked

### The Complete Flow

```
┌─────────────────┐
│   Select Item   │ → Choose product from dropdown
└────────┬────────┘
         │
┌────────▼────────┐
│  Enter Quantity │ → Number of units
└────────┬────────┘
         │
┌────────▼────────┐
│  Verify Price   │ → Auto-filled, can adjust
└────────┬────────┘
         │
┌────────▼────────┐
│  Add Customer   │ → Optional details
│    (Optional)   │
└────────┬────────┘
         │
┌────────▼────────┐
│ Select Payment  │ → Cash/Mobile/Bank/Credit
└────────┬────────┘
         │
┌────────▼────────┐
│     Submit      │ → Complete transaction
└────────┬────────┘
         │
┌────────▼────────┐
│  Invoice Ready  │ → Print or email
└─────────────────┘
```

## Detailed Sale Entry

### Step 1: Access Sale Form

**Sales → New Sale**

Or use keyboard shortcut: `Ctrl/Cmd + N`

### Step 2: Product Selection

**Stock Item (Required)**

```
Click dropdown → Search or scroll
Search by: Name, SKU, or Barcode

Example searches:
- "laptop" → Shows all laptops
- "HP-PAV" → Finds HP Pavilion series
- "1234567890" → Barcode search
```

**Smart Search Features:**
- Type-ahead suggestions
- Shows current stock level
- Displays selling price
- Indicates if low stock

**Example Dropdown Entry:**
```
HP Pavilion 15 Laptop (HP-PAV-15-001)
├─ In Stock: 10 units
├─ Price: 3,200,000 UGX
└─ Category: Electronics > Computers
```

### Step 3: Quantity Entry

**Quantity (Required)**

```yaml
Enter number of units: 1

Validation:
✅ Must be > 0
✅ Must be ≤ available stock
✅ Decimal allowed (e.g., 1.5 for 1.5kg)
⚠️ Warning if > stock level
```

**What happens when you enter quantity:**

```
You enter: 2
System checks:
├─ Available: 10 units ✅
├─ Enough stock: Yes ✅
├─ Calculates: 2 × 3,200,000 = 6,400,000 UGX
└─ Updates form: Total shows 6,400,000 UGX
```

### Step 4: Price Configuration

**Unit Price (Auto-filled)**

```yaml
Default: Product selling price (3,200,000 UGX)
Editable: Yes (if you have permission)

Common scenarios:
- Discount: Lower price (e.g., 3,000,000)
- Bulk discount: -10% for 5+ units
- Negotiated price: Customer-specific
- Promotional price: Special offers
```

**Price Override:**
```
Original Price: 3,200,000 UGX
Discount: 200,000 UGX (6.25%)
Final Price: 3,000,000 UGX

Or enter discount percentage:
Discount: 10%
Auto-calculates: 2,880,000 UGX
```

**Total Amount:**
```
Quantity × Unit Price = Total

Example:
2 units × 3,000,000 UGX = 6,000,000 UGX
```

### Step 5: Customer Information

**Customer Details (Optional but Recommended)**

```yaml
Customer Name: "John Doe"
  - For invoices and tracking
  - Builds customer database
  - Enables future analysis

Customer Phone: "+256-700-123456"
  - Contact for follow-ups
  - SMS notifications
  - Delivery coordination

Customer Email: "john@example.com"
  - Email invoices
  - Digital receipts
  - Marketing (with consent)

Customer Address: "Plot 123, Kampala"
  - Delivery address
  - Customer verification
  - Regional analysis
```

**Why capture customer details:**
- 📊 Track customer buying patterns
- 💰 Manage credit/payment plans
- 📧 Send invoices and receipts
- 🎯 Targeted marketing
- 🏆 Loyalty programs
- 📈 Customer lifetime value analysis

### Step 6: Payment Processing

**Payment Method (Required)**

```yaml
Options:
1. Cash
   - Immediate payment
   - Physical currency
   - Receipt required

2. Mobile Money
   - MTN/Airtel Money
   - Record transaction ID
   - Instant verification

3. Bank Transfer
   - Direct deposit
   - Reference number required
   - May need confirmation

4. Credit
   - Payment later
   - Credit terms apply
   - Track receivables
```

**Payment Status:**

```yaml
Completed: Fully paid
  - Stock deducted immediately
  - Invoice generated
  - Transaction closed

Partial Payment: Installments
  - Stock deducted (if policy allows)
  - Balance tracked
  - Follow-up required

Pending: Awaiting payment
  - Stock on hold
  - Confirmation needed
  - Time-limited
```

**Amount Paid:**
```yaml
Scenario 1: Full Payment
Total: 6,000,000 UGX
Paid: 6,000,000 UGX
Balance: 0 UGX
Status: Completed ✅

Scenario 2: Partial Payment
Total: 6,000,000 UGX
Paid: 3,000,000 UGX
Balance: 3,000,000 UGX
Status: Partial Payment ⏳

Scenario 3: Overpayment (Cash)
Total: 6,000,000 UGX
Paid: 7,000,000 UGX
Change: 1,000,000 UGX 💵
```

### Step 7: Additional Information

**Financial Period:**
```yaml
Auto-selected: Current active period
Purpose: Organize accounting
Report: Period-specific analysis
```

**Sale Date:**
```yaml
Default: Today
Editable: Yes (for backdating)
Format: YYYY-MM-DD
```

**Notes:**
```yaml
Optional field for:
- Special instructions
- Discount reasons
- Delivery details
- Payment terms
- Reference numbers

Example:
"10% discount approved by manager.
 Delivery scheduled for Dec 15.
 Reference: INV-2025-1234"
```

### Step 8: Submit Sale

Click **Submit** button

**Processing:**
```
1. Validating data... ✅
2. Checking stock... ✅ (10 → 8 units)
3. Recording sale... ✅ (Sale #1234)
4. Updating inventory... ✅
5. Generating invoice... ✅ (INV-2025-1234)
6. Creating receipt... ✅

Success! Sale completed.
```

**Post-Submit Actions:**
- 📄 **Print Invoice**: Send to printer
- 📧 **Email Invoice**: Send to customer
- 📱 **SMS Receipt**: Text receipt to customer
- 📋 **New Sale**: Process another transaction
- 👁️ **View Sale**: See sale details
- 📊 **View Report**: Daily sales summary

## Payment Methods

### Cash Payments

**Best for:**
- Walk-in customers
- Small transactions
- Immediate settlement

**Process:**
```yaml
1. Select: Cash
2. Enter: Amount received
3. System calculates: Change (if any)
4. Give: Change to customer
5. Print: Receipt
```

**Cash Handling Tips:**
- Count cash in front of customer
- Verify large denominations
- Store in secure location
- Reconcile at end of day
- Deposit regularly

**Cash Drawer:**
```
Opening Float: 200,000 UGX
+ Cash Sales: 5,430,000 UGX
- Cash Expenses: 150,000 UGX
= Expected: 5,480,000 UGX
Actual Count: 5,480,000 UGX
Difference: 0 UGX ✅
```

### Mobile Money

**Popular in:** Uganda, Kenya, Tanzania, Ghana

**Providers:**
- MTN Mobile Money
- Airtel Money
- Telecel Cash (Vodafone)
- T-Kash

**Process:**
```yaml
1. Select: Mobile Money
2. Provide: Your business number
3. Customer: Sends payment
4. Receive: SMS confirmation
5. Record: Transaction ID
6. Verify: Amount received
7. Complete: Sale
```

**Transaction Record:**
```
Date: 9 Dec 2025, 2:45 PM
From: +256-700-123456 (John Doe)
To: +256-750-888999 (Your Business)
Amount: 6,000,000 UGX
Fee: 9,500 UGX
Transaction ID: ABC1234567890
Status: Successful ✅
```

**Best Practices:**
- Always verify transaction ID
- Check sender's number matches customer
- Confirm amount received (after fees)
- Keep SMS confirmations
- Reconcile daily with provider statement

### Bank Transfer

**Best for:**
- Large transactions
- Business-to-business
- International customers

**Process:**
```yaml
1. Provide: Bank account details
2. Customer: Makes transfer
3. Receive: Bank notification
4. Verify: Reference number
5. Confirm: Amount credited
6. Complete: Sale
```

**Bank Details to Provide:**
```
Bank Name: Stanbic Bank Uganda
Account Name: Your Business Name Ltd
Account Number: 1234567890
Branch: Kampala Road
Swift Code: SBICUGKXXXX (for international)
Reference: INV-2025-1234
```

**Verification:**
```
Expected: 6,000,000 UGX
Received: 5,990,000 UGX
Difference: 10,000 UGX (bank charges)

Action: Confirm with customer or absorb cost
```

### Credit Sales

**For trusted customers only!**

**Setup Credit Terms:**
```yaml
Credit Limit: 10,000,000 UGX
Payment Terms: Net 30 days
Grace Period: 5 days
Late Fee: 2% per week
```

**Credit Sale Process:**
```yaml
1. Check: Customer credit limit
2. Verify: Current balance
3. Calculate: New total
4. Ensure: Within limit
5. Record: Sale as credit
6. Track: Payment due date
7. Follow-up: Before due date
```

**Credit Tracking:**
```
Customer: ABC Company Ltd
Credit Limit: 10,000,000 UGX
Current Balance: 4,500,000 UGX
Available Credit: 5,500,000 UGX

New Sale: 3,000,000 UGX
New Balance: 7,500,000 UGX
Remaining: 2,500,000 UGX
```

**Payment Due:**
```
Sale Date: 9 Dec 2025
Terms: Net 30
Due Date: 8 Jan 2026
Reminder: 5 Jan 2026
Late After: 13 Jan 2026
```

## Partial Payments

### Setting Up Installments

**Example: Laptop for 3,200,000 UGX**

```yaml
Total Amount: 3,200,000 UGX
Down Payment: 1,000,000 UGX (31%)
Balance: 2,200,000 UGX

Payment Plan:
- Today: 1,000,000 UGX
- 15 Dec: 1,100,000 UGX
- 30 Dec: 1,100,000 UGX
```

**Recording First Payment:**
```yaml
Sale Information:
  Stock Item: "HP Pavilion 15 Laptop"
  Quantity: 1
  Total: 3,200,000 UGX
  
Payment:
  Method: "Cash"
  Amount Paid: 1,000,000 UGX
  Balance: 2,200,000 UGX
  Status: "Partial Payment"
  
Notes: "Installment 1 of 3. Next payment 1,100,000 on 15 Dec"
```

### Tracking Installments

**View in Sale Records:**
```
Sale #1234 - HP Pavilion 15 Laptop
├─ Total: 3,200,000 UGX
├─ Paid: 1,000,000 UGX (31%)
├─ Balance: 2,200,000 UGX
└─ Status: Partial Payment ⏳

Payment History:
9 Dec 2025: 1,000,000 UGX (Cash) ✅
15 Dec 2025: 1,100,000 UGX (Pending) ⏳
30 Dec 2025: 1,100,000 UGX (Pending) ⏳
```

### Recording Subsequent Payments

**When customer returns:**

1. Go to **Sales → Sale Records**
2. Find the sale (Search by customer or sale number)
3. Click **Add Payment**
4. Enter:
   ```yaml
   Amount: 1,100,000 UGX
   Method: Mobile Money
   Date: 15 Dec 2025
   Transaction ID: XYZ123456
   ```
5. Save

**Updated Status:**
```
Paid: 2,100,000 UGX (66%)
Balance: 1,100,000 UGX
Status: Still Partial ⏳
```

### Completing Payment

**Final installment:**
```yaml
Amount: 1,100,000 UGX
Balance: 0 UGX
Status: Completed ✅
```

**What happens:**
- Payment history complete
- Customer notified
- Invoice marked paid
- Credit (if applicable) restored
- Transaction closed

## Sale Status

### Status Types

**1. Completed ✅**
```
Fully paid
Stock deducted
Invoice issued
Transaction closed
```

**2. Partial Payment ⏳**
```
Partially paid
Balance outstanding
Follow-up required
Payment plan active
```

**3. Pending ⏸️**
```
Awaiting confirmation
Stock on hold
Temporary status
Time-limited
```

**4. Cancelled ❌**
```
Transaction voided
Stock restored
Refund issued (if paid)
Audit trail maintained
```

**5. Refunded 🔄**
```
Full/partial refund
Stock returned
Credit note issued
Tracked separately
```

### Status Workflow

```
┌──────────┐
│  Pending │ → Initial state
└────┬─────┘
     │
     ├──→ Payment confirmed
     │
┌────▼──────────┐
│  Partial Pay  │ → Some payment received
└────┬──────────┘
     │
     ├──→ Balance paid
     │
┌────▼──────────┐
│   Completed   │ → Fully paid
└───────────────┘

Or:

┌──────────┐
│  Any     │ → Cancelled/Refunded
└────┬─────┘
     │
┌────▼──────────┐
│  Cancelled    │ → Voided
│  or Refunded  │
└───────────────┘
```

## Invoices & Receipts

### Auto-Generated Invoice

Every completed sale generates an invoice:

**Invoice Format:**
```
┌─────────────────────────────────────┐
│       YOUR BUSINESS NAME            │
│       123 Main Street               │
│       +256-XXX-XXXXXX               │
├─────────────────────────────────────┤
│  INVOICE                            │
│  INV-2025-1234                      │
│  Date: 9 December 2025              │
├─────────────────────────────────────┤
│  Bill To:                           │
│  John Doe                           │
│  +256-700-123456                    │
│  john@example.com                   │
├─────────────────────────────────────┤
│  Item         Qty  Price     Total  │
│  ───────────────────────────────────│
│  HP Pavilion   1   3,200,000 3,200,000│
│                                     │
│  Subtotal:           3,200,000 UGX  │
│  Discount:                  0 UGX   │
│  Tax:                       0 UGX   │
│  Total:              3,200,000 UGX  │
│                                     │
│  Paid:               3,200,000 UGX  │
│  Balance:                   0 UGX   │
├─────────────────────────────────────┤
│  Payment: Cash                      │
│  Status: Paid                       │
│                                     │
│  Thank you for your business!       │
└─────────────────────────────────────┘
```

### Invoice Actions

**Print Invoice:**
- Click **Print** button
- Opens print dialog
- Printer-friendly format
- A4 or thermal printer support

**Email Invoice:**
- Enter customer email
- Click **Email**
- PDF attached
- Customizable template

**Download PDF:**
- Click **Download**
- Saves to computer
- Shareable file
- Archive copy

**SMS Receipt:**
- Short version via SMS
- Transaction summary
- Payment confirmation
- Contact info

### Receipt Templates

**Simple Receipt (Thermal Printer):**
```
    YOUR BUSINESS
  123 Main Street
 +256-XXX-XXXXXX

Date: 9 Dec 2025 2:45PM
Sale: #1234

HP Pavilion 15    3,200,000
Quantity: 1

Total:            3,200,000
Paid:             3,200,000
Change:                   0

Payment: Cash

Thank you!
www.yourbusiness.com
```

**Detailed Receipt (A4):**
- Company letterhead
- Itemized list
- Terms & conditions
- Return policy
- Contact information
- QR code (optional)

## Sale Modifications

### Editing Sales

**What can be edited:**
- Customer information ✅
- Payment status ✅
- Notes ✅
- Payment method ✅

**What cannot be edited:**
- Stock item ❌ (create new sale instead)
- Quantity ❌ (affects stock, refund instead)
- Original prices ❌ (audit trail)
- Date ❌ (accountability)

**To edit:**
1. Go to **Sales → Sale Records**
2. Find sale
3. Click **Edit**
4. Modify allowed fields
5. Save

### Cancelling Sales

**When to cancel:**
- Customer changed mind (before delivery)
- Payment failed
- Data entry error
- Stock not available

**Process:**
```yaml
1. Find: Sale in records
2. Click: Cancel button
3. Confirm: Reason for cancellation
4. System:
   - Marks sale as cancelled
   - Restores stock
   - Reverses payment (if any)
   - Updates reports
   - Creates audit log
```

**Cancellation Note:**
```
Reason: "Customer cancelled order"
Cancelled By: Admin User
Date: 9 Dec 2025, 3:15 PM
Stock Restored: Yes (10 → 11 units)
Refund: 3,200,000 UGX
```

### Processing Refunds

**Refund Types:**

**1. Full Refund:**
```yaml
Original Sale: 3,200,000 UGX
Refund: 3,200,000 UGX (100%)
Customer Receives: Full amount back
Stock: Item returned to inventory
```

**2. Partial Refund:**
```yaml
Original Sale: 3,200,000 UGX
Refund: 500,000 UGX (15.6%)
Reason: "Minor defect, customer keeping item"
Customer Receives: 500,000 UGX
Stock: No change
```

**3. Exchange:**
```yaml
Return: HP Pavilion 15 (3,200,000 UGX)
Exchange For: Dell Inspiron (2,800,000 UGX)
Difference: 400,000 UGX refunded
Stock: HP +1, Dell -1
```

**Refund Process:**
```
1. Customer returns item
2. Inspect condition
3. Verify original sale
4. Process refund
5. Update inventory
6. Issue credit note
7. Record in system
```

## Best Practices

### Daily Operations

**Morning Routine:**
```
✅ Count cash drawer
✅ Verify opening float
✅ Check pending sales
✅ Review stock levels
✅ Prepare invoices
✅ Test printer/scanner
```

**During Sales:**
```
✅ Always verify stock before promising
✅ Capture customer details
✅ Double-check quantities
✅ Verify payment before delivery
✅ Issue receipts immediately
✅ Update system in real-time
```

**Evening Routine:**
```
✅ Count cash drawer
✅ Reconcile payments
✅ Review day's sales
✅ Check for errors
✅ Backup data
✅ Prepare deposit
```

### Customer Service

**Professional Sales Process:**
1. Greet warmly
2. Understand needs
3. Recommend products
4. Explain features/benefits
5. Handle objections
6. Close sale
7. Provide receipt
8. Thank customer
9. Invite return visit

**Communication:**
- Be clear about prices
- Explain payment options
- Mention warranties
- Provide contact info
- Follow up (if credit)

### Data Quality

**Always Record:**
- ✅ Customer name (even walk-ins: "Walk-in 1")
- ✅ Contact (phone or email)
- ✅ Payment method
- ✅ Transaction IDs (mobile money, bank)
- ✅ Any discounts given

**Never:**
- ❌ Process without recording
- ❌ Use fake customer data
- ❌ Skip receipts
- ❌ Manual stock adjustments
- ❌ Override prices without reason

### Security

**Protect Your System:**
- Unique logins per user
- Log out when away
- Don't share passwords
- Review audit logs
- Limit refund permissions
- Verify large transactions

**Fraud Prevention:**
- Count cash visibly
- Verify mobile money SMS
- Confirm bank transfers
- Check product before accepting returns
- Document everything
- Review suspicious patterns

## Troubleshooting

### Common Issues

**"Item not found in dropdown"**
```
Solutions:
✅ Check item status (must be Active)
✅ Verify company selected
✅ Check stock quantity > 0
✅ Refresh page
✅ Check spelling
```

**"Insufficient stock"**
```
Solutions:
✅ Check current quantity
✅ Reduce sale quantity
✅ Receive new stock first
✅ Or allow negative stock (if permitted)
```

**"Payment not reflecting"**
```
Solutions:
✅ Check payment status
✅ Verify financial period active
✅ Refresh reports
✅ Clear cache
✅ Check database connection
```

**"Invoice not generating"**
```
Solutions:
✅ Check PDF library installed
✅ Verify storage permissions
✅ Check company logo exists
✅ Review error logs
✅ Try different browser
```

## Next Steps

- **[Sale Records](/docs/sale-records.md)** - Manage sales history
- **[Customer Management](/docs/customer-management.md)** - Track customers
- **[Invoices](/docs/invoices.md)** - Customize invoices
- **[Sales Reports](/docs/sales-reports.md)** - Analyze performance

---

> **Pro Tip**: Use keyboard shortcuts for faster sales processing. Press `Ctrl/Cmd + N` for new sale, and `Tab` to navigate between fields quickly.
