<?php

namespace Database\Seeders;

use App\Models\AccountCategory;
use App\Models\Company;
use App\Models\Expense;
use App\Models\FinancialPeriod;
use App\Models\PurchaseOrder;
use App\Models\SaleRecord;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CompleteDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::beginTransaction();

        try {
            // Create 3 demo companies
            $companies = $this->createDemoCompanies();

            foreach ($companies as $company) {
                // Create users for each company
                $users = $this->createCompanyUsers($company);

                // Create stock categories and items
                $categories = $this->createStockCategories($company);
                $stockItems = $this->createStockItems($company, $categories, $users[0]);

                // Create financial period
                $period = $this->createFinancialPeriod($company, $users[0]);

                // Create purchase orders
                $this->createPurchaseOrders($company, $stockItems, $users[0], $period);

                // Create sales records
                $this->createSaleRecords($company, $stockItems, $users[0], $period);

                // Create expenses
                $this->createExpenses($company, $users[0], $period);
            }

            DB::commit();
            $this->command->info('✅ Demo data seeded successfully!');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('❌ Error seeding demo data: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Create demo companies
     */
    private function createDemoCompanies(): array
    {
        $companies = [];

        $companyData = [
            [
                'name' => 'TechStore Electronics',
                'phone_number' => '+1-555-0101',
                'phone_number_2' => '+1-555-0102',
                'email' => 'info@techstore.demo',
                'address' => '123 Tech Avenue, Silicon Valley, CA 94025',
                'slogan' => 'Innovation at Your Fingertips',
                'about' => 'Leading provider of cutting-edge electronics and technology solutions.',
                'currency' => 'USD',
                'status' => 'Active',
                'license_package' => 'Premium',
                'license_expire' => now()->addYear(),
            ],
            [
                'name' => 'Fashion Hub Boutique',
                'phone_number' => '+1-555-0201',
                'phone_number_2' => '+1-555-0202',
                'email' => 'contact@fashionhub.demo',
                'address' => '456 Style Street, New York, NY 10001',
                'slogan' => 'Style That Speaks',
                'about' => 'Premium fashion retailer offering latest trends and timeless classics.',
                'currency' => 'USD',
                'status' => 'Active',
                'license_package' => 'Standard',
                'license_expire' => now()->addMonths(6),
            ],
            [
                'name' => 'MediCare Pharmacy',
                'phone_number' => '+1-555-0301',
                'phone_number_2' => '+1-555-0302',
                'email' => 'support@medicare.demo',
                'address' => '789 Health Boulevard, Boston, MA 02101',
                'slogan' => 'Your Health, Our Priority',
                'about' => 'Trusted pharmacy providing quality healthcare products and services.',
                'currency' => 'USD',
                'status' => 'Active',
                'license_package' => 'Premium',
                'license_expire' => now()->addYears(2),
            ],
        ];

        foreach ($companyData as $data) {
            // Create owner first
            $owner = User::create([
                'first_name' => explode(' ', $data['name'])[0],
                'last_name' => 'Admin',
                'username' => $data['email'],
                'email' => $data['email'],
                'password' => Hash::make('password123'),
                'phone_number' => $data['phone_number'],
                'status' => 'Active',
            ]);

            // Assign admin role
            $owner->roles()->attach(1); // Assuming 1 is admin role

            // Create company
            $data['owner_id'] = $owner->id;
            $company = Company::create($data);

            // Update owner's company_id
            $owner->company_id = $company->id;
            $owner->save();

            $companies[] = $company;
            $this->command->info("✓ Created company: {$company->name}");
        }

        return $companies;
    }

    /**
     * Create users for a company
     */
    private function createCompanyUsers(Company $company): array
    {
        $users = [];

        // Get the owner
        $owner = User::find($company->owner_id);
        $users[] = $owner;

        // Create additional staff members
        $staffData = [
            [
                'first_name' => 'Sales',
                'last_name' => 'Manager',
                'email' => "sales@{$company->id}.demo",
                'role_id' => 2, // Assuming 2 is manager role
            ],
            [
                'first_name' => 'Stock',
                'last_name' => 'Keeper',
                'email' => "stock@{$company->id}.demo",
                'role_id' => 3, // Assuming 3 is staff role
            ],
        ];

        foreach ($staffData as $data) {
            $user = User::create([
                'company_id' => $company->id,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'username' => $data['email'],
                'email' => $data['email'],
                'password' => Hash::make('password123'),
                'phone_number' => '+1-555-'.rand(1000, 9999),
                'status' => 'Active',
            ]);

            $user->roles()->attach($data['role_id']);
            $users[] = $user;
        }

        return $users;
    }

    /**
     * Create stock categories for a company
     */
    private function createStockCategories(Company $company): array
    {
        $categories = [];

        $categoryData = $this->getCategoryDataForCompany($company);

        foreach ($categoryData as $catData) {
            $category = StockCategory::create([
                'company_id' => $company->id,
                'created_by_id' => $company->owner_id,
                'name' => $catData['name'],
                'description' => $catData['description'],
                'status' => 'Active',
            ]);

            // Create subcategories
            foreach ($catData['subcategories'] as $subCatName) {
                StockSubCategory::create([
                    'company_id' => $company->id,
                    'created_by_id' => $company->owner_id,
                    'stock_category_id' => $category->id,
                    'name' => $subCatName,
                    'description' => "Sub-category for {$subCatName}",
                    'status' => 'Active',
                ]);
            }

            $categories[] = $category;
        }

        return $categories;
    }

    /**
     * Get category data based on company type
     */
    private function getCategoryDataForCompany(Company $company): array
    {
        if (str_contains($company->name, 'Tech')) {
            return [
                ['name' => 'Smartphones', 'description' => 'Mobile phones and accessories', 'subcategories' => ['Android', 'iOS', 'Accessories']],
                ['name' => 'Laptops', 'description' => 'Portable computers', 'subcategories' => ['Gaming', 'Business', 'Student']],
                ['name' => 'Accessories', 'description' => 'Tech accessories', 'subcategories' => ['Cables', 'Cases', 'Chargers']],
            ];
        } elseif (str_contains($company->name, 'Fashion')) {
            return [
                ['name' => 'Men\'s Wear', 'description' => 'Fashion for men', 'subcategories' => ['Shirts', 'Pants', 'Shoes']],
                ['name' => 'Women\'s Wear', 'description' => 'Fashion for women', 'subcategories' => ['Dresses', 'Tops', 'Accessories']],
                ['name' => 'Kids Wear', 'description' => 'Fashion for children', 'subcategories' => ['Boys', 'Girls', 'Infants']],
            ];
        } else { // Pharmacy
            return [
                ['name' => 'Prescription Drugs', 'description' => 'Prescription medications', 'subcategories' => ['Antibiotics', 'Pain Relief', 'Chronic Care']],
                ['name' => 'OTC Medicines', 'description' => 'Over-the-counter products', 'subcategories' => ['Cold & Flu', 'Vitamins', 'First Aid']],
                ['name' => 'Personal Care', 'description' => 'Personal care products', 'subcategories' => ['Skincare', 'Oral Care', 'Hair Care']],
            ];
        }
    }

    /**
     * Create stock items for a company
     */
    private function createStockItems(Company $company, array $categories, User $user): array
    {
        $stockItems = [];

        foreach ($categories as $category) {
            $subcategories = StockSubCategory::where('stock_category_id', $category->id)->get();

            foreach ($subcategories as $subcategory) {
                // Create 10-15 items per subcategory
                $itemCount = rand(10, 15);

                for ($i = 1; $i <= $itemCount; $i++) {
                    $buyingPrice = rand(10, 500);
                    $sellingPrice = $buyingPrice * (1 + (rand(20, 60) / 100)); // 20-60% markup
                    $quantity = rand(50, 500);

                    $item = StockItem::create([
                        'company_id' => $company->id,
                        'created_by_id' => $user->id,
                        'stock_category_id' => $category->id,
                        'stock_sub_category_id' => $subcategory->id,
                        'name' => $this->generateProductName($category->name, $subcategory->name, $i),
                        'sku' => strtoupper(substr($company->name, 0, 3)).'-'.strtoupper(substr($subcategory->name, 0, 3)).'-'.str_pad($i, 4, '0', STR_PAD_LEFT),
                        'barcode' => ''.rand(1000000000000, 9999999999999),
                        'description' => $this->generateProductDescription($category->name, $subcategory->name),
                        'buying_price' => $buyingPrice,
                        'selling_price' => $sellingPrice,
                        'original_quantity' => $quantity,
                        'current_quantity' => $quantity,
                        'measuring_unit' => $this->getMeasuringUnit($category->name),
                        'stock_status' => 'In Stock',
                        'status' => 'Active',
                    ]);

                    $stockItems[] = $item;
                }
            }
        }

        $this->command->info("  ✓ Created ".count($stockItems)." stock items");

        return $stockItems;
    }

    /**
     * Generate product name
     */
    private function generateProductName(string $category, string $subcategory, int $index): string
    {
        $brands = ['Premium', 'Standard', 'Economy', 'Deluxe', 'Pro', 'Elite', 'Classic', 'Modern'];
        $models = ['A', 'B', 'C', 'X', 'Y', 'Z'];

        return $brands[array_rand($brands)].' '.$subcategory.' '.$models[array_rand($models)].$index;
    }

    /**
     * Generate product description
     */
    private function generateProductDescription(string $category, string $subcategory): string
    {
        return "High-quality {$subcategory} from our {$category} collection. Perfect for everyday use with excellent durability and performance.";
    }

    /**
     * Get measuring unit based on category
     */
    private function getMeasuringUnit(string $category): string
    {
        if (str_contains(strtolower($category), 'drug') || str_contains(strtolower($category), 'medicine')) {
            return 'Pieces';
        }

        return 'Pieces';
    }

    /**
     * Create financial period
     */
    private function createFinancialPeriod(Company $company, User $user): FinancialPeriod
    {
        $period = FinancialPeriod::create([
            'company_id' => $company->id,
            'created_by_id' => $user->id,
            'name' => now()->year.' - Annual Period',
            'start_date' => now()->startOfYear(),
            'end_date' => now()->endOfYear(),
            'description' => 'Current financial year period',
            'status' => 'Active',
        ]);

        $this->command->info('  ✓ Created financial period');

        return $period;
    }

    /**
     * Create purchase orders
     */
    private function createPurchaseOrders(Company $company, array $stockItems, User $user, FinancialPeriod $period): void
    {
        // Create 20-30 purchase orders
        $orderCount = rand(20, 30);

        for ($i = 0; $i < $orderCount; $i++) {
            // Random date within last 3 months
            $orderDate = now()->subDays(rand(0, 90));

            // Select random items (5-15 items per order)
            $itemCount = rand(5, 15);
            $selectedItems = array_rand($stockItems, $itemCount);
            if (! is_array($selectedItems)) {
                $selectedItems = [$selectedItems];
            }

            $totalAmount = 0;
            $orderItems = [];

            foreach ($selectedItems as $itemIndex) {
                $item = $stockItems[$itemIndex];
                $quantity = rand(10, 50);
                $amount = $item->buying_price * $quantity;
                $totalAmount += $amount;

                $orderItems[] = [
                    'stock_item_id' => $item->id,
                    'quantity' => $quantity,
                    'unit_price' => $item->buying_price,
                    'amount' => $amount,
                ];
            }

            $order = PurchaseOrder::create([
                'company_id' => $company->id,
                'created_by_id' => $user->id,
                'financial_period_id' => $period->id,
                'supplier_name' => $this->generateSupplierName(),
                'supplier_phone' => '+1-555-'.rand(1000, 9999),
                'supplier_email' => 'supplier'.rand(1, 100).'@demo.com',
                'order_date' => $orderDate,
                'delivery_date' => $orderDate->copy()->addDays(rand(1, 7)),
                'order_number' => 'PO-'.now()->year.'-'.str_pad($i + 1, 5, '0', STR_PAD_LEFT),
                'total_amount' => $totalAmount,
                'payment_status' => ['Paid', 'Partially Paid', 'Pending'][rand(0, 2)],
                'amount_paid' => $totalAmount * (rand(0, 100) / 100),
                'status' => 'Completed',
                'notes' => 'Demo purchase order',
            ]);
        }

        $this->command->info("  ✓ Created {$orderCount} purchase orders");
    }

    /**
     * Generate supplier name
     */
    private function generateSupplierName(): string
    {
        $names = [
            'Global Supplies Inc',
            'Premium Distributors Ltd',
            'Wholesale Direct Co',
            'Quality Imports LLC',
            'Elite Suppliers Group',
            'Trade Partners International',
            'Bulk Goods Corporation',
            'Direct Source Suppliers',
        ];

        return $names[array_rand($names)];
    }

    /**
     * Create sale records
     */
    private function createSaleRecords(Company $company, array $stockItems, User $user, FinancialPeriod $period): void
    {
        // Create 100-200 sale records
        $saleCount = rand(100, 200);

        for ($i = 0; $i < $saleCount; $i++) {
            // Random date within last 3 months
            $saleDate = now()->subDays(rand(0, 90));

            // Select random item
            $item = $stockItems[array_rand($stockItems)];
            $quantity = rand(1, 10);

            // Skip if not enough stock
            if ($item->current_quantity < $quantity) {
                continue;
            }

            $totalAmount = $item->selling_price * $quantity;
            $amountPaid = $totalAmount * (rand(80, 100) / 100); // 80-100% paid

            SaleRecord::create([
                'company_id' => $company->id,
                'created_by_id' => $user->id,
                'financial_period_id' => $period->id,
                'stock_item_id' => $item->id,
                'sale_date' => $saleDate,
                'receipt_number' => 'INV-'.now()->year.'-'.str_pad($i + 1, 6, '0', STR_PAD_LEFT),
                'customer_name' => $this->generateCustomerName(),
                'customer_phone' => '+1-555-'.rand(1000, 9999),
                'customer_email' => 'customer'.rand(1, 500).'@demo.com',
                'quantity' => $quantity,
                'unit_price' => $item->selling_price,
                'total_amount' => $totalAmount,
                'amount_paid' => $amountPaid,
                'balance' => $totalAmount - $amountPaid,
                'payment_status' => $amountPaid >= $totalAmount ? 'Paid' : 'Pending',
                'payment_method' => ['Cash', 'Card', 'Mobile Money', 'Bank Transfer'][rand(0, 3)],
                'status' => 'Completed',
                'notes' => 'Demo sale transaction',
            ]);
        }

        $this->command->info("  ✓ Created {$saleCount} sale records");
    }

    /**
     * Generate customer name
     */
    private function generateCustomerName(): string
    {
        $firstNames = ['John', 'Jane', 'Michael', 'Sarah', 'David', 'Emily', 'Robert', 'Lisa', 'James', 'Mary'];
        $lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez'];

        return $firstNames[array_rand($firstNames)].' '.$lastNames[array_rand($lastNames)];
    }

    /**
     * Create expenses
     */
    private function createExpenses(Company $company, User $user, FinancialPeriod $period): void
    {
        // Get expense categories
        $categories = AccountCategory::where('company_id', $company->id)
            ->where('type', 'Expense')
            ->get();

        if ($categories->isEmpty()) {
            return;
        }

        // Create 30-50 expense records
        $expenseCount = rand(30, 50);

        for ($i = 0; $i < $expenseCount; $i++) {
            $category = $categories->random();
            $amount = rand(50, 2000);
            $expenseDate = now()->subDays(rand(0, 90));

            Expense::create([
                'company_id' => $company->id,
                'created_by_id' => $user->id,
                'financial_period_id' => $period->id,
                'account_category_id' => $category->id,
                'expense_date' => $expenseDate,
                'amount' => $amount,
                'payment_method' => ['Cash', 'Card', 'Bank Transfer', 'Cheque'][rand(0, 3)],
                'reference_number' => 'EXP-'.now()->year.'-'.str_pad($i + 1, 5, '0', STR_PAD_LEFT),
                'description' => $this->generateExpenseDescription($category->name),
                'status' => 'Approved',
            ]);
        }

        $this->command->info("  ✓ Created {$expenseCount} expense records");
    }

    /**
     * Generate expense description
     */
    private function generateExpenseDescription(string $category): string
    {
        $descriptions = [
            'Monthly expense for '.$category,
            'Payment for '.$category.' services',
            'Recurring '.$category.' cost',
            'Regular '.$category.' expenditure',
        ];

        return $descriptions[array_rand($descriptions)];
    }
}
