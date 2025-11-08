<?php

namespace App\Http\Controllers;

use App\Models\BudgetItem;
use App\Models\Company;
use App\Models\ContributionRecord;
use App\Models\StockSubCategory;
use App\Models\User;
use App\Models\Utils;
use Dflydev\DotAccessData\Util;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ApiController extends BaseController
{


    public function file_uploading(Request $r)
    {
        $path = Utils::file_upload($r->file('photo'));
        if ($path == '') {
            Utils::error("File not uploaded.");
        }
        Utils::success([
            'file_name' => $path,
        ], "File uploaded successfully.");
    }

    public function manifest(Request $r)
    {
        $u = Utils::get_user($r);
        if ($u == null) {
            Utils::error("Unauthonticated.");
        }
        $roles = DB::table('admin_role_users')->where('user_id', $u->id)->get();
        $company = Company::find($u->company_id);
        $data = [
            'name' => 'Invetor-Track',
            'short_name' => 'IT',
            'description' => 'Inventory Management System',
            'version' => '1.0.0',
            'author' => 'M. Muhido',
            'user' => $u,
            'roles' => $roles,
            'company' => $company,
        ];
        Utils::success($data, "Success.");
    }

    public function my_list(Request $r, $model)
    {
        $u = Utils::get_user($r);
        if ($u == null) {
            Utils::error("Unauthonticated.");
        }
        $model = "App\Models\\" . $model;
        $data = $model::where('company_id', $u->company_id)->limit(100000)->get();
        Utils::success($data, "Listed successfully.");
    }





    public function budget_item_create(Request $r)
    {
        $u = Utils::get_user($r);
        if ($u == null) {
            Utils::error("Unauthonticated.");
        }
        $model = BudgetItem::class;
        $object = BudgetItem::find($r->get('id'));
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
            if (!in_array($key, $columns)) {
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
            $object->save();
        } catch (\Exception $e) {
            Utils::error($e->getMessage());
        }
        if ($object == null) {
            Utils::error("Failed to save.");
        }

        $new_object = $model::find($object->id);

        if ($isEdit) {
            Utils::success($new_object, "Updated successfully.");
        } else {
            Utils::success($new_object, "Created successfully.");
        }
    }

    /**
     * Quick Add Product - AJAX endpoint for instant product creation
     * Uses Laravel Admin web authentication
     */
    public function product_quick_add(Request $r)
    {
        // Use Laravel Admin authentication
        $u = \Encore\Admin\Facades\Admin::user();
        
        if ($u == null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Please log in.',
            ], 401);
        }

        // Validate required fields
        $r->validate([
            'name' => 'required|string|max:255',
            'selling_price' => 'required|numeric|min:0',
        ]);

        try {
            // Auto-generate SKU if not provided
            $sku = $r->get('sku');
            if (empty($sku)) {
                $sku = 'PROD-' . time() . '-' . rand(1000, 9999);
            }

            // Create the product
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
                'message' => 'Product added successfully! ✅',
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
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Quick Sale Recording - AJAX endpoint
     * Uses Laravel Admin web authentication
     */
    public function quick_sale_record(Request $r)
    {
        // Use Laravel Admin authentication
        $u = \Encore\Admin\Facades\Admin::user();
        
        if ($u == null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        try {
            // Validate input
            $validator = \Validator::make($r->all(), [
                'stock_item_id' => 'required|exists:stock_items,id',
                'quantity' => 'required|numeric|min:1',
                'price' => 'nullable|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                ], 422);
            }

            $stockItem = \App\Models\StockItem::find($r->stock_item_id);
            
            // Check if product belongs to user's company
            if ($stockItem->company_id != $u->company_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found',
                ], 404);
            }

            // Check stock availability
            if ($stockItem->current_quantity < $r->quantity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient stock! Available: ' . $stockItem->current_quantity . ' units',
                ], 422);
            }

            // Use selling price if not provided
            $salePrice = $r->price ?? $stockItem->selling_price;

            // Create stock record (sale)
            $stockRecord = new \App\Models\StockRecord();
            $stockRecord->company_id = $u->company_id;
            $stockRecord->stock_item_id = $stockItem->id;
            $stockRecord->quantity = -abs($r->quantity); // Negative for sale
            $stockRecord->type = 'Sale';
            $stockRecord->created_by_id = $u->id;
            $stockRecord->description = $r->description ?? 'Quick sale recorded';
            $stockRecord->save();

            // Update stock quantity
            $stockItem->current_quantity -= $r->quantity;
            $stockItem->save();

            // Calculate totals
            $totalAmount = $salePrice * $r->quantity;
            $profit = ($salePrice - $stockItem->buying_price) * $r->quantity;

            return response()->json([
                'success' => true,
                'message' => 'Sale recorded successfully!',
                'data' => [
                    'id' => $stockRecord->id,
                    'product' => $stockItem->name,
                    'quantity' => $r->quantity,
                    'price' => $salePrice,
                    'total' => $totalAmount,
                    'profit' => $profit,
                    'remaining_stock' => $stockItem->current_quantity,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Global Search - AJAX endpoint for searching across products, categories, sales
     * Uses Laravel Admin web authentication
     */
    public function global_search(Request $r)
    {
        // Use Laravel Admin authentication
        $u = \Encore\Admin\Facades\Admin::user();
        
        if ($u == null) {
            return response()->json([
                'products' => [],
                'categories' => [],
                'sales' => [],
            ], 401);
        }

        $query = $r->get('q', '');
        $companyId = $u->company_id;

        // Search Products (limit 10)
        $products = \App\Models\StockItem::where('company_id', $companyId)
            ->where(function($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('sku', 'like', "%{$query}%")
                  ->orWhere('barcode', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get(['id', 'name', 'sku', 'current_quantity', 'selling_price']);

        // Search Categories (limit 5)
        $categories = \App\Models\StockSubCategory::where('company_id', $companyId)
            ->where('name', 'like', "%{$query}%")
            ->withCount('stock_items')
            ->limit(5)
            ->get(['id', 'name']);

        // Search Sales/Stock Records (limit 10)
        $sales = \App\Models\StockRecord::where('company_id', $companyId)
            ->whereHas('stock_item', function($q) use ($query) {
                $q->where('name', 'like', "%{$query}%");
            })
            ->with('stock_item:id,name')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $salesFormatted = $sales->map(function($sale) {
            return [
                'id' => $sale->id,
                'product_name' => $sale->stock_item ? $sale->stock_item->name : 'N/A',
                'date' => date('d M Y', strtotime($sale->created_at)),
                'quantity' => $sale->quantity,
                'total' => $sale->total,
            ];
        });

        return response()->json([
            'products' => $products,
            'categories' => $categories,
            'sales' => $salesFormatted,
        ]);
    }





    public function contribution_records_create(Request $r)
    {
        $u = Utils::get_user($r);
        if ($u == null) {
            Utils::error("Unauthonticated.");
        }

        $treasurer = null;
        //check if treasurer_id is not set and abort
        if ($r->treasurer_id == null) {
            Utils::error("Treasurer is required.");
        } else {
            $treasurer = User::find($r->treasurer_id);
            if ($treasurer == null) {
                Utils::error("Treasurer not found.");
            }
        }

        $model = ContributionRecord::class;
        $object = ContributionRecord::find($r->get('id'));
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
            if (!in_array($key, $columns)) {
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
        $object->treasurer_id = $treasurer->id; //set treasurer_id

        try {
            $object->save();
        } catch (\Exception $e) {
            Utils::error($e->getMessage());
        }
        if ($object == null) {
            Utils::error("Failed to save.");
        }

        $new_object = $model::find($object->id);

        if ($isEdit) {
            Utils::success($new_object, "Updated successfully.");
        } else {
            Utils::success($new_object, "Created successfully.");
        }
    }





    public function my_update(Request $r, $model)
    {
        $u = Utils::get_user($r);
        if ($u == null) {
            Utils::error("Unauthonticated.");
        }
        $model = "App\Models\\" . $model;
        $object = $model::find($r->get('id'));
        $isEdit = true;
        if ($object == null) {
            $object = new $model();
            $isEdit = false;
        }
        
        // SAAS Security: Verify existing record belongs to user's company
        if ($isEdit && $object->company_id != $u->company_id) {
            Utils::error("Access denied. You can only edit records from your company.");
        }


        $table_name = $object->getTable();
        $columns = Schema::getColumnListing($table_name);
        $except = ['id', 'created_at', 'updated_at'];
        $data = $r->all();

        foreach ($data as $key => $value) {
            if (!in_array($key, $columns)) {
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
                $file  = $r->file('photo');
                if ($file != null) {
                    $path = "";
                    try {
                        $path = Utils::file_upload($r->file('photo'));
                    } catch (\Exception $e) {
                        $path = "";
                    }
                    if (strlen($path) > 3) {
                        $fiel_name = $r->temp_file_field;
                        $object->$fiel_name = $path;
                    }
                }
            }
        }

        try {
            $object->save();
        } catch (\Exception $e) {
            Utils::error($e->getMessage());
        }
        $new_object = $model::find($object->id);

        if ($isEdit) {
            Utils::success($new_object, "Updated successfully.");
        } else {
            Utils::success($new_object, "Created successfully.");
        }
    }




    public function login(Request $r)
    {
        //check if email is provided
        if ($r->email == null) {
            Utils::error("Email is required.");
        }
        //check if email is valid
        if (!filter_var($r->email, FILTER_VALIDATE_EMAIL)) {
            //Utils::error("Email is invalid.");
        }

        //check if password is provided
        if ($r->password == null) {
            Utils::error("Password is required.");
        }

        $user = User::where('email', $r->email)->first();
        if ($user == null) {
            Utils::error("Account not found.");
        }

        if (!password_verify($r->password, $user->password)) {
            Utils::error("Invalid password.");
        }

        $company = Company::find($user->company_id);
        if ($company == null) {
            Utils::error("Company not found.");
        }

        Utils::success([
            'user' => $user,
            'company' => $company,
        ], "Login successful.");
    }


    public function register(Request $r)
    {

        if ($r->first_name == null) {
            Utils::error("First name is required.");
        }
        //check if last name is provided
        if ($r->last_name == null) {
            Utils::error("Last name is required.");
        }
        //check if email is provided
        if ($r->email == null) {
            Utils::error("Email is required.");
        }
        //check if email is valid
        if (!filter_var($r->email, FILTER_VALIDATE_EMAIL)) {
            Utils::error("Email is invalid.");
        }

        //check if email is already registered
        $u = User::where('email', $r->email)->first();
        if ($u != null) {
            Utils::error("Email is already registered.");
        }
        //check if password is provided
        if ($r->password == null) {
            Utils::error("Password is required.");
        }

        //check if company name is provided
        if ($r->company_name == null) {
            Utils::error("Company name is required.");
        }
        if ($r->currency == null) {
            Utils::error("Currency is required.");
        }

        $new_user = new User();
        $new_user->first_name = $r->first_name;
        $new_user->last_name = $r->last_name;
        $new_user->username = $r->email;
        $new_user->email = $r->email;
        $new_user->password = password_hash($r->password, PASSWORD_DEFAULT);
        $new_user->name = $r->first_name . " " . $r->last_name;
        $new_user->phone_number = $r->phone_number;
        $new_user->company_id = 1;
        $new_user->status = "Active";

        try {
            $new_user->save();
        } catch (\Exception $e) {
            Utils::error($e->getMessage());
        }

        $registered_user = User::find($new_user->id);
        if ($registered_user == null) {
            Utils::error("Failed to register user.");
        }

        $company = new Company();
        $company->owner_id = $registered_user->id;
        $company->name = $r->company_name;
        $company->email = $r->email;
        $company->phone_number = $r->phone_number;
        $company->status = 'Active';
        $company->currency = $r->currency;
        $company->license_expire = date('Y-m-d', strtotime("+1 year"));

        try {
            $company->save();
        } catch (\Exception $e) {
            Utils::error($e->getMessage());
        }

        $registered_company = Company::find($company->id);
        if ($registered_company == null) {
            Utils::error("Failed to register company.");
        }

        //DB instert into admin_role_users
        DB::table('admin_role_users')->insert([
            'user_id' => $registered_user->id,
            'role_id' => 2,
        ]);

        Utils::success([
            'user' => $registered_user,
            'company' => $registered_company,
        ], "Registration successful.");
    }
}
