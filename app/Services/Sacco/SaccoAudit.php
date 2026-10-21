<?php

namespace App\Services\Sacco;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * The SACCO's business actions in the one audit table (docs/akiba/04-architecture.md §8, AK-33): dotted `sacco.*`
 * actions (`sacco.approval.requested`, `sacco.period.closed`, `sacco.ledger.verify_failed`, `sacco.sensitive_view`…)
 * beside AuditLogger's model diffs, so the portal's Audit trail reads one place. The money's own audit is the
 * append-only, hash-chained journal; this records who asked, who decided and who looked.
 *
 * The context never carries a NIN (callers pass masked values only). Like AuditLogger, a failure to write is
 * logged and never stops the action it describes.
 */
final class SaccoAudit
{
    /** @param  array<string, mixed>  $context */
    public static function record(string $action, Model|string $subject, int $companyId, array $context = [], ?int $actorId = null, ?int $subjectId = null): void
    {
        try {
            $actorId ??= auth()->id() === null ? null : (int) auth()->id();
            // audit_logs.user_id points at the legacy `users` table (as in AuditLogger); staff are admin_users, so the actor
            // is kept in the values and user_id only when such a row exists.
            $userId = $actorId !== null && DB::table('users')->where('id', $actorId)->exists() ? $actorId : null;
            DB::table('audit_logs')->insert([
                'user_id' => $userId,
                'model_type' => $subject instanceof Model ? get_class($subject) : $subject,
                'model_id' => (int) ($subject instanceof Model ? $subject->getKey() : ($subjectId ?? 0)),
                'action' => $action,
                'old_values' => null,
                'new_values' => json_encode(['actor_id' => $actorId] + $context),
                'ip_address' => Request::ip(),
                'user_agent' => Request::header('User-Agent'),
                'url' => Request::fullUrl(),
                'company_id' => $companyId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('SaccoAudit failed: '.$e->getMessage(), ['action' => $action, 'company_id' => $companyId]);
        }
    }
}
