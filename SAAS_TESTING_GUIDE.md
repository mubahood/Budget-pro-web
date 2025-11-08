# 🧪 SAAS TESTING GUIDE
## Budget Pro Web - Multi-Tenancy Testing

**Date:** November 8, 2025

---

## 🎯 TESTING OBJECTIVES

1. Verify users can ONLY see their own company's data
2. Verify users CANNOT access other companies' data
3. Verify dashboard shows company-specific statistics
4. Verify CompanyController restrictions work properly

---

## 📋 PRE-REQUISITES

### Test Data Setup

You need:
- ✅ 2 test companies
- ✅ 2 test users (one per company)
- ✅ Sample data for each company (stock items, sales, etc.)

---

## 🧪 TEST CASES

### TEST 1: User Login and Company Assignment ✅

**Steps:**
1. Login as User A (Company A)
2. Check `auth()->user()->company_id` returns Company A's ID
3. Login as User B (Company B)
4. Check `auth()->user()->company_id` returns Company B's ID

**Expected Result:** Each user has correct company_id assigned

---

### TEST 2: Stock Items Isolation ✅

**Steps:**
1. Create stock item as User A
2. Login as User A and query: `StockItem::all()`
3. Login as User B and query: `StockItem::all()`

**Expected Result:**
- User A sees ONLY Company A's items
- User B sees ONLY Company B's items
- No cross-company data visible

---

### TEST 3: Sale Records Isolation ✅

**Steps:**
1. Create sale record as User A
2. Login as User A: `SaleRecord::all()`
3. Login as User B: `SaleRecord::all()`

**Expected Result:**
- User A sees ONLY Company A's sales
- User B sees ONLY Company B's sales

---

### TEST 4: Dashboard Statistics ✅

**Steps:**
1. Login as User A
2. Visit dashboard at `/admin`
3. Note down statistics (total sales, inventory, etc.)
4. Login as User B
5. Visit dashboard at `/admin`
6. Compare statistics

**Expected Result:**
- Statistics are different for each user
- User A sees ONLY Company A's data in stats
- User B sees ONLY Company B's data in stats

---

### TEST 5: Direct ID Access Prevention ✅

**Steps:**
1. Login as User A
2. Note down an ID of Company B's stock item (e.g., ID = 999)
3. Try: `StockItem::find(999)`
4. Try accessing `/admin/stock-items/999`

**Expected Result:**
- `StockItem::find(999)` returns `null`
- Accessing URL shows 404 or Access Denied
- User A CANNOT see Company B's data even with direct ID

---

### TEST 6: CompanyController Security ✅

**Steps:**
1. Login as regular User A (not super admin)
2. Visit `/admin/companies`
3. Try to edit Company B

**Expected Result:**
- Grid shows ONLY Company A
- Cannot see Company B in list
- Attempting to access Company B edit form → Access Denied

---

### TEST 7: Budget Items Isolation ✅

**Steps:**
1. Create budget item as User A
2. Login as User A: `BudgetItem::all()`
3. Login as User B: `BudgetItem::all()`

**Expected Result:**
- User A sees ONLY Company A's budget items
- User B sees ONLY Company B's budget items

---

### TEST 8: Financial Records Isolation ✅

**Steps:**
1. Create financial record as User A
2. Login as User A: `FinancialRecord::all()`
3. Login as User B: `FinancialRecord::all()`

**Expected Result:**
- User A sees ONLY Company A's financial records
- User B sees ONLY Company B's financial records

---

### TEST 9: Purchase Orders Isolation ✅

**Steps:**
1. Create purchase order as User A
2. Login as User A: `PurchaseOrder::all()`
3. Login as User B: `PurchaseOrder::all()`

**Expected Result:**
- User A sees ONLY Company A's purchase orders
- User B sees ONLY Company B's purchase orders

---

### TEST 10: Auto-Assignment Test ✅

**Steps:**
1. Login as User A
2. Create new StockItem WITHOUT specifying `company_id`
   ```php
   $item = StockItem::create([
       'name' => 'Test Product',
       'sku' => 'TEST123',
       // DO NOT set company_id
   ]);
   ```
3. Check `$item->company_id`

**Expected Result:**
- `company_id` is automatically set to User A's company_id
- Item is associated with Company A automatically

---

## 🔍 TESTING COMMANDS

### Console Testing (Tinker)

```bash
php artisan tinker
```

#### Test Company Scope:
```php
// Login as User A
auth()->login(User::find(1)); // Replace 1 with User A's ID

// Check company assignment
auth()->user()->company_id; // Should return Company A's ID

// Query stock items (should only show Company A)
StockItem::count();
StockItem::all(); // Only Company A's items

// Switch to User B
auth()->login(User::find(2)); // Replace 2 with User B's ID
auth()->user()->company_id; // Should return Company B's ID

// Query stock items again
StockItem::count(); // Different count
StockItem::all(); // Only Company B's items
```

#### Test Cross-Company Access:
```php
// Login as User A
auth()->login(User::find(1));

// Try to find Company B's item by ID
$itemB = StockItem::withoutGlobalScope(CompanyScope::class)
    ->where('company_id', 2)
    ->first();
$itemB->id; // Note this ID

// Now try to access it normally
StockItem::find($itemB->id); // Should return null (filtered by scope)
```

#### Test Auto-Assignment:
```php
// Login as User A
auth()->login(User::find(1));

// Create item without company_id
$item = StockItem::create([
    'name' => 'Auto Test Product',
    'sku' => 'AUTO' . rand(1000, 9999),
    'stock_category_id' => 1,
    'stock_sub_category_id' => 1,
    'financial_period_id' => 1,
    'created_by_id' => 1,
    // NO company_id specified
]);

// Check company_id
$item->company_id; // Should equal User A's company_id
$item->company_id === auth()->user()->company_id; // Should be true
```

---

## 📊 EXPECTED RESULTS SUMMARY

### ✅ PASS Criteria

- [x] Each user can only query their own company's data
- [x] Direct ID access to other company's records returns null
- [x] Dashboard shows company-specific statistics
- [x] CompanyController restricts access to own company only
- [x] New records automatically get user's company_id
- [x] All models with CompanyScope filter correctly
- [x] Super admin can see all companies (with withoutGlobalScope)

### ❌ FAIL Criteria

- Data leakage: User A can see User B's data
- Cross-company access: User A can access Company B's record by ID
- Dashboard shows mixed company data
- Auto-assignment doesn't work: company_id is null or wrong
- CompanyController shows all companies to regular users

---

## 🐛 TROUBLESHOOTING

### Issue: User can see other company's data

**Solution:**
1. Check if model has CompanyScope: `use App\Scopes\CompanyScope;`
2. Check if model has booted() method with addGlobalScope
3. Clear cache: `php artisan cache:clear`
4. Restart PHP/server

### Issue: company_id not auto-assigned

**Solution:**
1. Check if user is authenticated: `auth()->check()`
2. Check if user has company_id: `auth()->user()->company_id`
3. Verify CompanyScope booting logic
4. Check model's $fillable includes 'company_id'

### Issue: Dashboard shows wrong data

**Solution:**
1. Check HomeController queries filter by company_id
2. Verify user's company_id is correct
3. Check if relationships properly filter

---

## 🎯 FINAL CHECKLIST

- [ ] All TEST 1-10 executed and passed
- [ ] No data leakage between companies
- [ ] Dashboard isolation confirmed
- [ ] CompanyController security verified
- [ ] Auto-assignment working correctly
- [ ] Cross-company access prevented
- [ ] Documentation reviewed
- [ ] Production deployment ready

---

**Test Date:** _______________  
**Tested By:** _______________  
**Result:** ✅ PASS / ❌ FAIL  
**Notes:** _______________
