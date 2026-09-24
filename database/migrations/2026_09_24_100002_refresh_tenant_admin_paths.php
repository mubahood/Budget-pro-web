<?php

use App\Support\AdminAccess;
use Illuminate\Database\Migrations\Migration;

/** Re-sync the tenant allow-list after adding /subscription-expired (P0-3). */
return new class extends Migration
{
    public function up(): void
    {
        AdminAccess::scopeTenantRoles();
    }

    public function down(): void
    {
    }
};
