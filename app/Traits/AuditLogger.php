<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait AuditLogger
{
    /**
     * Boot the auditable trait for a model.
     */
    public static function bootAuditLogger()
    {
        // Log model creation
        static::created(function ($model) {
            $model->logAudit('created', null, $model->getAttributes());
        });

        // Log model updates
        static::updated(function ($model) {
            $model->logAudit('updated', $model->getOriginal(), $model->getChanges());
        });

        // Log model deletion
        static::deleted(function ($model) {
            $model->logAudit('deleted', $model->getOriginal(), null);
        });
    }

    /**
     * Log an audit entry.
     *
     * @param string $action
     * @param array|null $oldValues
     * @param array|null $newValues
     * @return void
     */
    protected function logAudit(string $action, ?array $oldValues, ?array $newValues)
    {
        try {
            // Skip logging if we're in a testing environment or console without user
            if (!Auth::check() && !app()->runningInConsole()) {
                return;
            }

            // Get user ID - verify it exists in database
            $userId = Auth::id();
            
            // Verify user exists to prevent foreign key constraint errors
            if ($userId) {
                $userExists = DB::table('users')->where('id', $userId)->exists();
                if (!$userExists) {
                    // User doesn't exist in database, set to null
                    $userId = null;
                    Log::warning("AuditLogger: User ID {$userId} not found in database. Setting to null.");
                }
            }

            // Get company ID if the model has it
            $companyId = null;
            if (isset($this->company_id)) {
                $companyId = $this->company_id;
            } elseif (Auth::check() && Auth::user()->company_id) {
                $companyId = Auth::user()->company_id;
            }

            // Filter out sensitive fields
            $sensitiveFields = ['password', 'remember_token', 'api_token', 'otp_code', 'reset_token'];
            if ($oldValues) {
                $oldValues = $this->filterSensitiveData($oldValues, $sensitiveFields);
            }
            if ($newValues) {
                $newValues = $this->filterSensitiveData($newValues, $sensitiveFields);
            }

            // Create audit log entry in database
            DB::table('audit_logs')->insert([
                'user_id' => $userId, // Will be null if user doesn't exist
                'model_type' => get_class($this),
                'model_id' => $this->id,
                'action' => $action,
                'old_values' => $oldValues ? json_encode($oldValues) : null,
                'new_values' => $newValues ? json_encode($newValues) : null,
                'ip_address' => Request::ip(),
                'user_agent' => Request::header('User-Agent'),
                'url' => Request::fullUrl(),
                'company_id' => $companyId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Log to file for debugging
            Log::info("Audit Log: {$action} on " . get_class($this) . " #{$this->id} by user #{$userId}");
            
        } catch (\Exception $e) {
            // Don't fail the main operation if audit logging fails
            Log::error("AuditLogger failed: " . $e->getMessage());
        }
    }

    /**
     * Filter out sensitive data from audit logs.
     *
     * @param array $data
     * @param array $sensitiveFields
     * @return array
     */
    protected function filterSensitiveData(array $data, array $sensitiveFields): array
    {
        foreach ($sensitiveFields as $field) {
            if (isset($data[$field])) {
                $data[$field] = '[FILTERED]';
            }
        }
        return $data;
    }
}
