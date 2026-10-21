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
        'approve' => 'Approve voids, refunds and overrides with a till PIN',
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
        // Akiba Ledger (docs/akiba/04-architecture.md §6.1): offered only to SACCO accounts (see `module_permissions`).
        'sacco_view' => 'See the SACCO screens, members and balances',
        'sacco_members' => 'Register and edit members, nominees and documents',
        'sacco_kyc' => 'Verify members\' identity (check another officer\'s capture)',
        'sacco_teller' => 'Record deposits, share purchases, fees and repayments; print receipts',
        'sacco_withdraw' => 'Request withdrawals',
        'sacco_approve' => 'Check other officers\' requests: withdrawals, reversals, journals, periods',
        'sacco_loans' => 'Create, appraise and manage loan applications and guarantors',
        'sacco_loans_vote' => 'Vote on loans (with a committee position)',
        'sacco_disburse' => 'Request loan disbursements and payouts',
        'sacco_accounts' => 'Chart of accounts, manual journals, periods, tills and statements',
        'sacco_reports' => 'SACCO reports, statements and returns',
        'sacco_settings' => 'SACCO settings and products',
        'sacco_officers' => 'Appoint officers and end their terms',
        'sacco_audit_view' => 'Read the audit trail and everything else, read-only',
        'sacco_meetings' => 'Run savings-group meetings',
    ],

    // Permissions that belong to one module: a company is offered (and its owner holds) only its own modules'
    // ones beside the family's, so a shop's roles, permissions and auth/me are exactly what they were.
    'module_permissions' => [
        'sacco' => ['sacco_view', 'sacco_members', 'sacco_kyc', 'sacco_teller', 'sacco_withdraw', 'sacco_approve', 'sacco_loans', 'sacco_loans_vote',
            'sacco_disburse', 'sacco_accounts', 'sacco_reports', 'sacco_settings', 'sacco_officers', 'sacco_audit_view', 'sacco_meetings'],
    ],

    // What an account built around an exclusive module keeps of the family's permissions (the account itself).
    'exclusive_keeps' => ['view_reports', 'resolve_conflicts', 'manage_team', 'manage_settings', 'billing'],

    'roles' => [
        'owner' => ['label' => 'Owner', 'permissions' => ['*']],
        'manager' => ['label' => 'Manager', 'permissions' => ['sell', 'discount', 'void', 'refund', 'approve', 'restock', 'adjust', 'stock_take', 'manage_products', 'view_cost', 'view_profit', 'view_reports', 'manage_finance', 'manage_budget', 'resolve_conflicts']],
        'cashier' => ['label' => 'Cashier', 'permissions' => ['sell', 'refund']],
        'stock_keeper' => ['label' => 'Stock keeper', 'permissions' => ['restock', 'adjust', 'stock_take', 'manage_products', 'view_cost', 'resolve_conflicts']],
        'accountant' => ['label' => 'Accountant', 'permissions' => ['view_cost', 'view_profit', 'view_reports', 'manage_finance', 'manage_budget']],
        'viewer' => ['label' => 'Viewer', 'permissions' => ['view_reports']],
        // Akiba Ledger roles (04 §6.1): `module` keeps them to SACCO accounts, where the shop's roles are not offered.
        // A SACCO's viewer also gets sacco_view (Permissions::forRole). Positions (chair, committee…) are sacco_officers.
        'sacco_manager' => ['label' => 'SACCO manager', 'module' => 'sacco', 'permissions' => ['sacco_view', 'sacco_members', 'sacco_teller', 'sacco_withdraw', 'sacco_approve',
            'sacco_loans', 'sacco_disburse', 'sacco_accounts', 'sacco_reports', 'sacco_settings', 'sacco_officers']],
        'sacco_chair' => ['label' => 'Chairperson', 'module' => 'sacco', 'permissions' => ['sacco_view', 'sacco_approve', 'sacco_loans_vote', 'sacco_reports', 'sacco_officers', 'sacco_audit_view']],
        'sacco_treasurer' => ['label' => 'Treasurer', 'module' => 'sacco', 'permissions' => ['sacco_view', 'sacco_members', 'sacco_teller', 'sacco_withdraw', 'sacco_approve', 'sacco_disburse',
            'sacco_accounts', 'sacco_reports']],
        'sacco_secretary' => ['label' => 'Secretary', 'module' => 'sacco', 'permissions' => ['sacco_view', 'sacco_members', 'sacco_kyc', 'sacco_reports', 'sacco_meetings']],
        'sacco_loans_officer' => ['label' => 'Loans officer', 'module' => 'sacco', 'permissions' => ['sacco_view', 'sacco_members', 'sacco_teller', 'sacco_loans', 'sacco_reports']],
        'sacco_committee' => ['label' => 'Loans committee member', 'module' => 'sacco', 'permissions' => ['sacco_view', 'sacco_loans_vote', 'sacco_reports']],
        'sacco_auditor' => ['label' => 'Auditor (supervisory board)', 'module' => 'sacco', 'permissions' => ['sacco_view', 'sacco_reports', 'sacco_audit_view']],
        'sacco_teller' => ['label' => 'Teller', 'module' => 'sacco', 'permissions' => ['sacco_view', 'sacco_teller', 'sacco_withdraw']],
    ],

    // laravel-admin role slug each company role maps to (web menu + access).
    'admin_roles' => [
        'owner' => 'company', 'manager' => 'shop_manager', 'cashier' => 'shop_cashier', 'stock_keeper' => 'shop_stock_keeper',
        'accountant' => 'shop_accountant', 'viewer' => 'shop_viewer',
        // The classic panel has no SACCO screens (AK-20): SACCO staff get its read-only role.
        'sacco_manager' => 'shop_viewer', 'sacco_chair' => 'shop_viewer', 'sacco_treasurer' => 'shop_viewer', 'sacco_secretary' => 'shop_viewer',
        'sacco_loans_officer' => 'shop_viewer', 'sacco_committee' => 'shop_viewer', 'sacco_auditor' => 'shop_viewer', 'sacco_teller' => 'shop_viewer',
    ],
];
