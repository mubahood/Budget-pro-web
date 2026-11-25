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
        // Estate Ledger (docs/estate-ledger/04-architecture.md §7.1): offered only to rentals accounts (see `module_permissions`).
        'rentals_view' => 'See properties, units, tenants, leases and balances',
        'rentals_manage' => 'Add and edit landlords, properties, units and tenants; move tenants in and out',
        'rentals_collect' => 'Receive payments and print receipts',
        'rentals_bill' => 'Add charges, change rent, waive and void charges',
        'rentals_reverse' => 'Reverse payments, payouts and expenses',
        'rentals_landlords' => 'Landlord statements and payouts',
        'rentals_expenses' => 'Record expenses and move cash between accounts',
        'rentals_maintenance' => 'Maintenance requests and meter readings',
        'rentals_reports' => 'Reports and exports',
        'rentals_settings' => 'Settings, staff property assignments, tenant and landlord sign-ins',
        'rentals_close' => 'Close a month and issue its statements',
        'rentals_reopen' => 'Reopen a closed month, with a written reason',
        // Family Roots (the kin module): offered only to family accounts (see `module_permissions`).
        'kin_view' => 'See the family tree, people and branches',
        'kin_people' => 'Add and edit people, parents and spouses',
        'kin_branches' => 'Add and edit branches',
        'kin_settings' => 'Family settings',
        // UnityDesk (the unity module): offered only to association accounts (see `module_permissions`).
        'unity_view' => 'See the members, categories, chapters and subscriptions',
        'unity_members' => 'Add and edit members',
        'unity_finance' => 'Membership categories and fees, and record subscriptions and payments',
        'unity_chapters' => 'Add and edit chapters',
        'unity_settings' => 'Association settings',
    ],

    // Permissions that belong to one module: a company is offered (and its owner holds) only its own modules'
    // ones beside the family's, so a shop's roles, permissions and auth/me are exactly what they were.
    'module_permissions' => [
        'sacco' => ['sacco_view', 'sacco_members', 'sacco_kyc', 'sacco_teller', 'sacco_withdraw', 'sacco_approve', 'sacco_loans', 'sacco_loans_vote',
            'sacco_disburse', 'sacco_accounts', 'sacco_reports', 'sacco_settings', 'sacco_officers', 'sacco_audit_view', 'sacco_meetings'],
        'rentals' => ['rentals_view', 'rentals_manage', 'rentals_collect', 'rentals_bill', 'rentals_reverse', 'rentals_landlords', 'rentals_expenses',
            'rentals_maintenance', 'rentals_reports', 'rentals_settings', 'rentals_close', 'rentals_reopen'],
        'kin' => ['kin_view', 'kin_people', 'kin_branches', 'kin_settings'],
        'unity' => ['unity_view', 'unity_members', 'unity_finance', 'unity_chapters', 'unity_settings'],
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
        // Estate Ledger roles (04 §7.1): `module` keeps them to rentals accounts, where the shop's roles are not offered.
        // A rentals account's viewer also gets rentals_view and rentals_reports (Permissions::forRole). A caretaker sees
        // only the properties they are assigned to (RentalAccess::propertyIds, KR-17).
        'rentals_manager' => ['label' => 'Property manager', 'module' => 'rentals', 'permissions' => ['rentals_view', 'rentals_manage', 'rentals_collect', 'rentals_bill',
            'rentals_reverse', 'rentals_landlords', 'rentals_expenses', 'rentals_maintenance', 'rentals_reports', 'rentals_settings', 'rentals_close']],
        'rentals_accountant' => ['label' => 'Accountant', 'module' => 'rentals', 'permissions' => ['rentals_view', 'rentals_collect', 'rentals_bill', 'rentals_reverse',
            'rentals_landlords', 'rentals_expenses', 'rentals_reports', 'rentals_close']],
        'rentals_caretaker' => ['label' => 'Caretaker', 'module' => 'rentals', 'permissions' => ['rentals_view', 'rentals_collect', 'rentals_maintenance']],
        // Family Roots roles: `module` keeps them to family accounts. The head of the family is the account's owner;
        // a family's viewer also gets kin_view (Permissions::forRole). A member only reads.
        'kin_secretary' => ['label' => 'Secretary', 'module' => 'kin', 'permissions' => ['kin_view', 'kin_people', 'kin_branches']],
        'kin_treasurer' => ['label' => 'Treasurer', 'module' => 'kin', 'permissions' => ['kin_view']],
        'kin_branch_coordinator' => ['label' => 'Branch coordinator', 'module' => 'kin', 'permissions' => ['kin_view', 'kin_people']],
        'kin_member' => ['label' => 'Member', 'module' => 'kin', 'permissions' => ['kin_view']],
        // UnityDesk roles: the chair is the account's owner. A member reads their own record only (no unity_view:
        // the unity/ API answers them with themselves alone).
        'unity_secretary' => ['label' => 'Secretary', 'module' => 'unity', 'permissions' => ['unity_view', 'unity_members', 'unity_chapters']],
        'unity_treasurer' => ['label' => 'Treasurer', 'module' => 'unity', 'permissions' => ['unity_view', 'unity_finance']],
        'unity_chapter_lead' => ['label' => 'Chapter lead', 'module' => 'unity', 'permissions' => ['unity_view', 'unity_members']],
        'unity_member' => ['label' => 'Member', 'module' => 'unity', 'permissions' => []],
    ],

    // laravel-admin role slug each company role maps to (web menu + access).
    'admin_roles' => [
        'owner' => 'company', 'manager' => 'shop_manager', 'cashier' => 'shop_cashier', 'stock_keeper' => 'shop_stock_keeper',
        'accountant' => 'shop_accountant', 'viewer' => 'shop_viewer',
        // The classic panel has no SACCO screens (AK-20): SACCO staff get its read-only role.
        'sacco_manager' => 'shop_viewer', 'sacco_chair' => 'shop_viewer', 'sacco_treasurer' => 'shop_viewer', 'sacco_secretary' => 'shop_viewer',
        'sacco_loans_officer' => 'shop_viewer', 'sacco_committee' => 'shop_viewer', 'sacco_auditor' => 'shop_viewer', 'sacco_teller' => 'shop_viewer',
        // Nor any rental screens (the rental books live in the web portal and the Estate Ledger app).
        'rentals_manager' => 'shop_viewer', 'rentals_accountant' => 'shop_viewer', 'rentals_caretaker' => 'shop_viewer',
        // Nor any family screens (a family's records live in the web portal and the Family Roots app).
        'kin_secretary' => 'shop_viewer', 'kin_treasurer' => 'shop_viewer', 'kin_branch_coordinator' => 'shop_viewer', 'kin_member' => 'shop_viewer',
        // Nor any association screens (an association's members live in the web portal and the UnityDesk app).
        'unity_secretary' => 'shop_viewer', 'unity_treasurer' => 'shop_viewer', 'unity_chapter_lead' => 'shop_viewer', 'unity_member' => 'shop_viewer',
    ],
];
