<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * SAAS Enforcement Middleware
 * 
 * This middleware provides an additional layer of security by:
 * 1. Ensuring authenticated users always have a valid company_id
 * 2. Validating that company_id in requests matches authenticated user's company
 * 3. Preventing company_id tampering in form submissions
 * 4. Logging suspicious activity when company_id mismatch is detected
 */
class EnforceSaasIsolation
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only enforce for authenticated requests
        if (Auth::check()) {
            $user = Auth::user();
            
            // Security Check 1: User must have a company_id
            if (empty($user->company_id)) {
                // Log critical security issue
                Log::critical('User without company_id attempted to access system', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'ip' => $request->ip(),
                    'url' => $request->fullUrl(),
                ]);
                
                // Logout user and redirect to login
                Auth::logout();
                return redirect('/admin/auth/login')
                    ->with('error', 'Your account is not associated with any company. Please contact administrator.');
            }
            
            // Security Check 2: If request contains company_id, it must match user's company
            // This prevents tampering with form submissions or API requests
            if ($request->has('company_id')) {
                $requestCompanyId = $request->input('company_id');
                
                // Allow super admins to work across companies
                if ($user->user_type !== 'admin' && $requestCompanyId != $user->company_id) {
                    Log::warning('Company ID mismatch detected - potential security breach attempt', [
                        'user_id' => $user->id,
                        'user_company_id' => $user->company_id,
                        'requested_company_id' => $requestCompanyId,
                        'ip' => $request->ip(),
                        'url' => $request->fullUrl(),
                        'method' => $request->method(),
                    ]);
                    
                    // Override the company_id in request to user's company
                    $request->merge(['company_id' => $user->company_id]);
                }
            }
            
            // Security Check 3: Inject company_id into all requests that don't have it
            // This ensures forms without hidden company_id fields still work correctly
            if (!$request->has('company_id') && in_array($request->method(), ['POST', 'PUT', 'PATCH'])) {
                $request->merge(['company_id' => $user->company_id]);
            }
        }
        
        return $next($request);
    }
}
