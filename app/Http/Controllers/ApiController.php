<?php

namespace App\Http\Controllers;

use App\Models\BudgetItem;
use App\Models\BudgetItemCategory;
use App\Models\BudgetProgram;
use App\Models\Company;
use App\Models\ContributionRecord;
use App\Models\User;
use App\Models\Utils;
use App\Services\Onboarding\RegistrationService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy API (pre-v1) + web AJAX helpers for the laravel-admin panel.
 *
 * The mobile/third-party REST API lives under /api/v1 (see
 * app/Http/Controllers/Api/V1) and is the path forward for all new clients.
 *
 * The methods below (login, register, my_list, my_update, budget_item_create,
 * contribution_records_create) are kept ONLY because the currently-installed
 * mobile app (pre-v1, published build) still calls them directly with
 * `logged_in_user_id`-param auth. They are intentionally NOT used by anything
 * new — do not build new features against this controller. When the mobile
 * app has fully migrated to /api/v1 token auth, these can be retired.
 *
 * my_list/my_update are restricted to a fixed model whitelist (the exact set
 * the shipped app actually addresses) to close the arbitrary-model /
 * privilege-escalation hole that existed here previously, without changing
 * the request/response contract the app expects.
 */
class ApiController extends BaseController
{
    /**
     * Models reachable via the legacy generic api/{model} route — the exact
     * set the shipped mobile app sends today. Anything else is rejected.
     */
    private const LEGACY_MODEL_WHITELIST = [
        'BudgetProgram' => \App\Models\BudgetProgram::class,
        'BudgetItem' => \App\Models\BudgetItem::class,
        'BudgetItemCategory' => \App\Models\BudgetItemCategory::class,
        'ContributionRecord' => \App\Models\ContributionRecord::class,
        'StockItem' => \App\Models\StockItem::class,
        'StockCategory' => \App\Models\StockCategory::class,
        'StockSubCategory' => \App\Models\StockSubCategory::class,
        'StockRecord' => \App\Models\StockRecord::class,
        'FinancialPeriod' => \App\Models\FinancialPeriod::class,
        'FinancialCategory' => \App\Models\FinancialCategory::class,
        'FinancialReport' => \App\Models\FinancialReport::class,
        'FinancialRecord' => \App\Models\FinancialRecord::class,
        'User' => \App\Models\User::class,
    ];

    public function manifest(Request $r)
    {
        $u = Utils::get_user($r);
        if ($u == null) {
            Utils::error('Unauthonticated.');
        }
        $roles = DB::table('admin_role_users')->where('user_id', $u->id)->get();
        $company = Company::find($u->company_id);
        $data = [
            'name' => 'Budget Pro',
            'short_name' => 'BP',
            'description' => 'Inventory Management System',
            'version' => '1.0.0',
            'author' => 'M. Muhido',
            'user' => $u,
            'roles' => $roles,
            'company' => $company,
        ];
        Utils::success($data, 'Success.');
    }

    public function my_list(Request $r, $model)
    {
        $u = Utils::get_user($r);
        if ($u == null) {
            Utils::error('Unauthonticated.');
        }
        if (! isset(self::LEGACY_MODEL_WHITELIST[$model])) {
            Utils::error('Invalid model: '.$model);
        }
        $modelClass = self::LEGACY_MODEL_WHITELIST[$model];
        $data = $modelClass::where('company_id', $u->company_id)->limit(100000)->get();
        Utils::success($data, 'Listed successfully.');
    }

    public function budget_item_create(Request $r)
    {
        $u = Utils::get_user($r);
        if ($u == null) {
            Utils::error('Unauthonticated.');
        }
        $model = BudgetItem::class;
        $object = BudgetItem::where('id', $r->get('id'))->where('company_id', $u->company_id)->first();
        $isEdit = true;
        if ($object == null) {
            $object = new $model();
            $isEdit = false;
        }

        $table_name = $object->getTable();
        $columns = Schema::getColumnListing($table_name);
        $except = ['id', 'created_at', 'updated_at'];
        $data = $r->all();

        foreach ($data as $key => $value) {
            if (! in_array($key, $columns)) {
                continue;
            }
            if (in_array($key, $except)) {
                continue;
            }
            if ($value == null) {
                continue;
            }
            if ($value == '') {
                continue;
            }
            $object->$key = $value;
        }
        $object->company_id = $u->company_id;

        try {
            $object->saveQuietly();
        } catch (\Exception $e) {
            Utils::error($e->getMessage());
        }
        if ($object == null) {
            Utils::error('Failed to save.');
        }

        // saveQuietly() skips every Eloquent event, so BudgetItem::prepare()/
        // finalizer() never run here -- meaning the category/program rollup
        // totals (balance, percentage_done, is_complete) silently went stale
        // on every write through this endpoint. Mirrors the same cascade
        // MobileApiController::budgetItemSave already does after its own
        // saveQuietly() call.
        try {
            $cat = BudgetItemCategory::withoutGlobalScopes()->find($object->budget_item_category_id);
            if ($cat) {
                $cat->updateSelf();
            }
        } catch (\Throwable $th) {
            // Don't fail the response over a rollup recalculation issue.
        }

        $new_object = $model::find($object->id);

        if ($isEdit) {
            Utils::success($new_object, 'Updated successfully.');
        } else {
            Utils::success($new_object, 'Created successfully.');
        }
    }

    public function contribution_records_create(Request $r)
    {
        $u = Utils::get_user($r);
        if ($u == null) {
            Utils::error('Unauthonticated.');
        }

        $treasurer = null;
        if ($r->treasurer_id == null) {
            Utils::error('Treasurer is required.');
        } else {
            $treasurer = User::where('id', $r->treasurer_id)->where('company_id', $u->company_id)->first();
            if ($treasurer == null) {
                Utils::error('Treasurer not found.');
            }
        }

        $model = ContributionRecord::class;
        $object = ContributionRecord::where('id', $r->get('id'))->where('company_id', $u->company_id)->first();
        $isEdit = true;
        if ($object == null) {
            $object = new $model();
            $isEdit = false;
        }

        $table_name = $object->getTable();
        $columns = Schema::getColumnListing($table_name);
        $except = ['id', 'created_at', 'updated_at'];
        $data = $r->all();

        foreach ($data as $key => $value) {
            if (! in_array($key, $columns)) {
                continue;
            }
            if (in_array($key, $except)) {
                continue;
            }
            if ($value == null) {
                continue;
            }
            if ($value == '') {
                continue;
            }
            $object->$key = $value;
        }
        $object->company_id = $u->company_id;
        $object->treasurer_id = $treasurer->id;

        try {
            $object->saveQuietly();
        } catch (\Exception $e) {
            Utils::error($e->getMessage());
        }
        if ($object == null) {
            Utils::error('Failed to save.');
        }

        // saveQuietly() skips ContributionRecord::prepare()/finalizer(), so
        // the parent BudgetProgram's rollup totals silently went stale on
        // every write through this endpoint. Mirrors the same cascade
        // MobileApiController::contributionRecordSave already does.
        try {
            if ($object->budget_program_id) {
                BudgetProgram::recalculateFromChildren((int) $object->budget_program_id);
            }
        } catch (\Throwable $th) {
            // Don't fail the response over a rollup recalculation issue.
        }

        $new_object = $model::find($object->id);

        if ($isEdit) {
            Utils::success($new_object, 'Updated successfully.');
        } else {
            Utils::success($new_object, 'Created successfully.');
        }
    }

    public function my_update(Request $r, $model)
    {
        $u = Utils::get_user($r);
        if ($u == null) {
            Utils::error('Unauthonticated.');
        }
        if (! isset(self::LEGACY_MODEL_WHITELIST[$model])) {
            Utils::error('Invalid model: '.$model);
        }
        $modelClass = self::LEGACY_MODEL_WHITELIST[$model];
        $object = $modelClass::find($r->get('id'));
        $isEdit = true;
        if ($object == null) {
            $object = new $modelClass();
            $isEdit = false;
        }

        // SAAS Security: Verify existing record belongs to user's company
        if ($isEdit && $object->company_id != $u->company_id) {
            Utils::error('Access denied. You can only edit records from your company.');
        }

        $table_name = $object->getTable();
        $columns = Schema::getColumnListing($table_name);
        $except = ['id', 'created_at', 'updated_at'];
        $data = $r->all();

        foreach ($data as $key => $value) {
            if (! in_array($key, $columns)) {
                continue;
            }
            if (in_array($key, $except)) {
                continue;
            }
            if ($value == null) {
                continue;
            }
            if ($value == '') {
                continue;
            }
            $object->$key = $value;
        }
        $object->company_id = $u->company_id;

        //temp_image_field
        if ($r->temp_file_field != null) {
            if (strlen($r->temp_file_field) > 1) {
                $file = $r->file('photo');
                if ($file != null) {
                    $path = '';
                    try {
                        $path = Utils::file_upload($r->file('photo'));
                    } catch (\Exception $e) {
                        $path = '';
                    }
                    if (strlen($path) > 3) {
                        $fiel_name = $r->temp_file_field;
                        $object->$fiel_name = $path;
                    }
                }
            }
        }

        try {
            // StockItem/StockRecord/FinancialRecord derive required, NOT-NULL
            // fields (financial_period_id, stock_category_id, SKU, pricing) in
            // their `creating` hook, which the shipped app relies on and never
            // sends itself. saveQuietly() skips that hook and breaks these
            // three on create, so use a real save() for their creation only
            // (edits keep saveQuietly() — unchanged, lower-risk behavior).
            if (! $isEdit && in_array($model, ['StockItem', 'StockRecord', 'FinancialRecord'], true)) {
                $object->save();
            } else {
                $object->saveQuietly();
            }
        } catch (\Exception $e) {
            Utils::error($e->getMessage());
        }
        $new_object = $modelClass::find($object->id);

        if ($isEdit) {
            Utils::success($new_object, 'Updated successfully.');
        } else {
            Utils::success($new_object, 'Created successfully.');
        }
    }

    public function login(Request $r)
    {
        //check if email is provided
        if ($r->email == null) {
            Utils::error('Email is required.');
        }
        //check if email is valid
        if (! filter_var($r->email, FILTER_VALIDATE_EMAIL)) {
            //Utils::error("Email is invalid.");
        }

        //check if password is provided
        if ($r->password == null) {
            Utils::error('Password is required.');
        }

        $user = User::where('email', $r->email)->first();
        if ($user == null) {
            Utils::error('Account not found.');
        }

        if (! password_verify($r->password, $user->password)) {
            Utils::error('Invalid password.');
        }

        $company = Company::find($user->company_id);
        if ($company == null) {
            Utils::error('Company not found.');
        }

        // Additive: issues a Sanctum token alongside the legacy response so
        // the already-shipped mobile client (which never called /api/v1/auth
        // and never had a token) can start authenticating to /api/v1
        // endpoints — e.g. poultry sync — without any change to its login
        // request. Existing clients ignore the extra field; nothing about
        // the legacy response shape changes for them.
        $token = $user->createToken((string) ($r->input('device_name') ?: $r->userAgent() ?: 'api-token'))->plainTextToken;

        Utils::success([
            'user' => $user,
            'company' => $company,
            'token' => $token,
        ], 'Login successful.');
    }

    public function register(Request $r)
    {
        // Legacy mobile endpoint: same RegistrationService as /api/v1 and the web form (P0-13),
        // same response shape the shipped app expects.
        $validator = \Validator::make($r->all(), RegistrationService::rules(), RegistrationService::messages());
        if ($validator->fails()) {
            Utils::error($validator->errors()->first());
        }

        try {
            ['user' => $registered_user, 'company' => $registered_company] = app(RegistrationService::class)
                ->register($validator->validated(), RegistrationService::PRODUCT_BUDGET, 'legacy-api');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            Utils::error($e->getMessage());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Legacy registration failed', ['error' => $e->getMessage()]);
            Utils::error('Registration failed. Please try again.');
        }

        // Additive, same rationale as login(): lets the already-shipped mobile client
        // authenticate to /api/v1 (poultry sync, etc.) without any change to its request.
        $token = $registered_user->createToken((string) ($r->input('device_name') ?: $r->userAgent() ?: 'api-token'))->plainTextToken;

        Utils::success([
            'user' => $registered_user,
            'company' => $registered_company,
            'token' => $token,
        ], 'Registration successful.');
    }

    /**
     * Quick Add Product — AJAX endpoint for instant product creation.
     */
    public function product_quick_add(Request $r)
    {
        $u = \Encore\Admin\Facades\Admin::user();

        if ($u == null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Please log in.',
            ], 401);
        }

        $r->validate([
            'name' => 'required|string|max:255',
            'selling_price' => 'required|numeric|min:0',
        ]);

        try {
            $sku = $r->get('sku');
            if (empty($sku)) {
                $sku = 'PROD-'.time().'-'.rand(1000, 9999);
            }

            $product = new \App\Models\StockItem();
            $product->company_id = $u->company_id;
            $product->name = $r->get('name');
            $product->sku = $sku;
            $product->barcode = $r->get('barcode', '');
            $product->stock_sub_category_id = $r->get('stock_sub_category_id');
            $product->buying_price = $r->get('buying_price', 0);
            $product->selling_price = $r->get('selling_price');
            $product->current_quantity = $r->get('current_quantity', 0);
            $product->original_quantity = $r->get('current_quantity', 0);
            $product->created_by_id = $u->id;
            $product->description = $r->get('description', '');

            $product->save();

            return response()->json([
                'success' => true,
                'message' => 'Product added successfully!',
                'data' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'selling_price' => number_format($product->selling_price),
                    'stock' => number_format($product->current_quantity),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Quick Sale Recording — AJAX endpoint. Goes through the same checkout as the
     * POS and the app (receipt number, payment, stock rules, customer), paid in full.
     */
    public function quick_sale_record(Request $r)
    {
        /** @var \App\Models\User|null $u */
        $u = \Encore\Admin\Facades\Admin::user();

        if ($u == null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }
        if (! \App\Services\Team\Permissions::can($u, 'sell')) {
            return response()->json(['success' => false, 'message' => 'Your role does not allow selling.'], 403);
        }

        $validator = \Validator::make($r->all(), [
            'stock_item_id' => 'required|integer',
            'quantity' => 'required|numeric|min:0.001',
            'price' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|max:30',
            'customer_name' => 'nullable|string|max:120',
            'customer_phone' => 'nullable|string|max:30',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $stockItem = \App\Models\StockItem::withoutGlobalScopes()->where('company_id', $u->company_id)->where('is_deleted', 0)->find((int) $r->stock_item_id);
        if ($stockItem === null) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
            ], 404);
        }
        if ($r->filled('price') && round((float) $r->price, 2) !== round((float) $stockItem->selling_price, 2) && ! \App\Services\Team\Permissions::can($u, 'discount')) {
            return response()->json(['success' => false, 'message' => 'Your role does not allow changing prices.'], 403);
        }

        $unitPrice = $r->filled('price') ? round((float) $r->price, 2) : round((float) $stockItem->selling_price, 2);
        $total = round($unitPrice * (float) $r->quantity, 2);
        $method = $r->input('payment_method', 'cash');

        try {
            $result = (new \App\Services\Shop\SaleService())->checkout((int) $u->company_id, (int) $u->id, [
                'items' => [['stock_item_id' => $stockItem->id, 'quantity' => (float) $r->quantity, 'unit_price' => $unitPrice]],
                'payments' => $total > 0 ? [['method' => $method, 'amount' => $total]] : [],
                'payments_explicit' => true,
                'payment_method' => $method,
                'customer_name' => $r->input('customer_name') ?: null,
                'customer_phone' => $r->input('customer_phone') ?: null,
                'notes' => $r->input('description') ?: null,
            ]);
        } catch (\App\Exceptions\BusinessRuleException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $sale = $result['sale'];
        $stockItem->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Sale recorded successfully!',
            'data' => [
                'id' => $sale->id,
                'receipt_number' => $sale->receipt_number,
                'url' => admin_url('sale-records/'.$sale->id),
                'product' => $stockItem->name,
                'quantity' => (float) $r->quantity,
                'price' => $unitPrice,
                'total' => (float) $sale->total_amount,
                'remaining_stock' => (float) $stockItem->current_quantity,
            ],
        ]);
    }

    /**
     * Global Search — AJAX endpoint for the admin command palette. Only the
     * signed-in user's shop; live (not deleted) rows; every result links to its page.
     */
    public function global_search(Request $r)
    {
        /** @var \App\Models\User|null $u */
        $u = \Encore\Admin\Facades\Admin::user();
        $empty = ['products' => [], 'categories' => [], 'sales' => [], 'customers' => [], 'suppliers' => []];

        if ($u == null) {
            return response()->json($empty, 401);
        }

        $query = trim((string) $r->get('q', ''));
        if (mb_strlen($query) < 2) {
            return response()->json($empty);
        }
        $companyId = (int) $u->company_id;
        $like = '%'.addcslashes($query, '%_\\').'%';
        $perms = \App\Services\Team\Permissions::of($u);

        $products = DB::table('stock_items')->where('company_id', $companyId)->where('is_deleted', 0)
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('barcode', $query))
            ->orderBy('name')->limit(8)->get(['id', 'name', 'sku', 'current_quantity', 'selling_price'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'current_quantity' => (float) $p->current_quantity,
                'selling_price' => (float) $p->selling_price, 'url' => admin_url('stock-items/'.$p->id)]);

        $categories = DB::table('stock_sub_categories as s')->where('s.company_id', $companyId)->where('s.is_deleted', 0)->where('s.name', 'like', $like)
            ->orderBy('s.name')->limit(5)->get(['s.id', 's.name'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name,
                'products_count' => DB::table('stock_items')->where('company_id', $companyId)->where('is_deleted', 0)->where('stock_sub_category_id', $c->id)->count(),
                'url' => admin_url('stock-items?stock_sub_category_id='.$c->id)]);

        $sales = in_array('sell', $perms, true) || in_array('view_reports', $perms, true)
            ? DB::table('sale_records')->where('company_id', $companyId)->where('is_deleted', 0)
                ->where(fn ($q) => $q->where('receipt_number', 'like', $like)->orWhere('invoice_number', 'like', $like)->orWhere('provisional_number', $query)
                    ->orWhere('customer_name', 'like', $like)->orWhere('customer_phone', 'like', $like))
                ->orderByDesc('id')->limit(8)->get(['id', 'receipt_number', 'customer_name', 'customer_phone', 'sale_date', 'total_amount', 'status'])
                ->map(fn ($s) => ['id' => $s->id, 'receipt_number' => $s->receipt_number, 'customer_name' => $s->customer_name, 'customer_phone' => $s->customer_phone,
                    'date' => $s->sale_date ? date('d M Y', strtotime((string) $s->sale_date)) : '', 'total' => (float) $s->total_amount, 'status' => $s->status,
                    'url' => admin_url('sale-records/'.$s->id)])
            : collect();

        $customers = in_array('sell', $perms, true)
            ? DB::table('customers')->where('company_id', $companyId)->where('is_deleted', 0)
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('phone', 'like', $like))
                ->orderBy('name')->limit(5)->get(['id', 'name', 'phone', 'balance'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'balance' => (float) $c->balance, 'url' => admin_url('customers/'.$c->id)])
            : collect();

        $suppliers = in_array('restock', $perms, true)
            ? DB::table('suppliers')->where('company_id', $companyId)->where('is_deleted', 0)
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('phone', 'like', $like))
                ->orderBy('name')->limit(5)->get(['id', 'name', 'phone', 'balance'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'balance' => (float) $c->balance, 'url' => admin_url('suppliers/'.$c->id)])
            : collect();

        return response()->json([
            'products' => $products->values(),
            'categories' => $categories->values(),
            'sales' => $sales->values(),
            'customers' => $customers->values(),
            'suppliers' => $suppliers->values(),
        ]);
    }
}
