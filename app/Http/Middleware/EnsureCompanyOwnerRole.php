<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EnsureCompanyOwnerRole
{
    /**
     * Handle an incoming request.
     * 
     * This middleware ensures that company owners always have role ID 2
     * It runs on every authenticated request to maintain role integrity
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Only check for authenticated users
        if (Auth::guard('admin')->check()) {
            $user = Auth::guard('admin')->user();
            
            // Skip if user doesn't have a company_id
            if (!empty($user->company_id)) {
                // Check if this user is the owner of their company
                $company = DB::table('companies')
                    ->where('id', $user->company_id)
                    ->where('owner_id', $user->id)
                    ->first();

                if ($company) {
                    // This user IS a company owner - ensure they have role ID 2
                    $companyOwnerRoleId = 2;
                    
                    // Check if role assignment already exists
                    $existingRole = DB::table('admin_role_users')
                        ->where('user_id', $user->id)
                        ->where('role_id', $companyOwnerRoleId)
                        ->first();

                    if (!$existingRole) {
                        // Role not assigned - assign it now
                        DB::table('admin_role_users')->insert([
                            'role_id' => $companyOwnerRoleId,
                            'user_id' => $user->id,
                        ]);

                        Log::warning('Company owner role was missing - auto-assigned via middleware', [
                            'user_id' => $user->id,
                            'company_id' => $user->company_id,
                            'role_id' => $companyOwnerRoleId,
                        ]);
                        
                        // Reload the user's roles to reflect the change immediately
                        Auth::guard('admin')->setUser($user->fresh());
                    }
                }
            }
        }

        return $next($request);
    }
}
