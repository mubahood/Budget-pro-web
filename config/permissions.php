<?php

/*
| Per-company roles and their default permissions (plan C5, P3-4). Companies can
| override single permissions per role (company_role_permissions). Owner always
| has everything; billing, team and settings are owner-only by default.
*/
return [
    'permissions' => [
        'sell' => 'Sell and take payments',
        'discount' => 'Give discounts and change prices',
        'void' => 'Void a sale',
        'refund' => 'Take returns and refund',
        'restock' => 'Receive stock and manage suppliers',
        'adjust' => 'Adjust stock (damage, loss, corrections)',
        'stock_take' => 'Post stock counts',
        'manage_products' => 'Add and edit products, categories, units',
        'view_cost' => 'See cost prices',
        'view_profit' => 'See profit',
        'view_reports' => 'See reports and dashboards',
        'manage_finance' => 'Record income, expenses and financial periods',
        'manage_budget' => 'Manage budgets and contributions',
        'resolve_conflicts' => 'Resolve sync problems',
        'manage_team' => 'Invite and manage team members',
        'manage_settings' => 'Change shop settings',
        'billing' => 'Manage the plan and payments',
    ],

    'roles' => [
        'owner' => ['label' => 'Owner', 'permissions' => ['*']],
        'manager' => ['label' => 'Manager', 'permissions' => ['sell', 'discount', 'void', 'refund', 'restock', 'adjust', 'stock_take', 'manage_products', 'view_cost', 'view_profit', 'view_reports', 'manage_finance', 'manage_budget', 'resolve_conflicts']],
        'cashier' => ['label' => 'Cashier', 'permissions' => ['sell', 'refund']],
        'stock_keeper' => ['label' => 'Stock keeper', 'permissions' => ['restock', 'adjust', 'stock_take', 'manage_products', 'view_cost', 'resolve_conflicts']],
        'accountant' => ['label' => 'Accountant', 'permissions' => ['view_cost', 'view_profit', 'view_reports', 'manage_finance', 'manage_budget']],
        'viewer' => ['label' => 'Viewer', 'permissions' => ['view_reports']],
    ],

    // laravel-admin role slug each company role maps to (web menu + access).
    'admin_roles' => [
        'owner' => 'company', 'manager' => 'shop_manager', 'cashier' => 'shop_cashier', 'stock_keeper' => 'shop_stock_keeper',
        'accountant' => 'shop_accountant', 'viewer' => 'shop_viewer',
    ],
];
