<?php

namespace App\Services\Notifications;

use App\Models\Company;
use App\Models\User;
use App\Services\Messaging\Messenger;
use Illuminate\Support\Facades\DB;

/**
 * Business notifications (plan C7, P3-5): an in-app entry for the right people
 * plus an outbound message on the channels each person chose.
 */
class Notifier
{
    /** key => [label, default channels, default on?] */
    public const TYPES = [
        'daily_summary' => ['Daily sales summary (8 pm)', ['whatsapp', 'in_app'], false],
        'low_stock' => ['Low stock digest', ['in_app'], true],
        'unsynced_device' => ['A phone has not synced for 3 days', ['whatsapp', 'in_app'], true],
        'cash_variance' => ['Cash-up difference', ['in_app'], true],
        'billing' => ['Plan and payment reminders', ['whatsapp', 'mail', 'in_app'], true],
        'team' => ['Team changes', ['in_app'], true],
    ];

    public function __construct(private readonly Messenger $messenger = new Messenger())
    {
    }

    /** @return array{enabled: bool, channels: array<int, string>} */
    public function preference(int $userId, string $key): array
    {
        $row = DB::table('notification_preferences')->where('user_id', $userId)->where('key', $key)->first();
        [$label, $channels, $on] = self::TYPES[$key] ?? ['', ['in_app'], true];

        return $row ? ['enabled' => (bool) $row->enabled, 'channels' => json_decode($row->channels, true) ?: []] : ['enabled' => $on, 'channels' => $channels];
    }

    public function setPreference(int $userId, string $key, bool $enabled, array $channels): void
    {
        DB::table('notification_preferences')->updateOrInsert(['user_id' => $userId, 'key' => $key],
            ['enabled' => $enabled, 'channels' => json_encode(array_values(array_intersect($channels, ['whatsapp', 'sms', 'mail', 'in_app']))), 'updated_at' => now(), 'created_at' => now()]);
    }

    /** Owner and managers of a company (who business alerts go to). */
    public function managers(int $companyId): array
    {
        $company = Company::withoutGlobalScopes()->find($companyId);
        $ids = DB::table('company_members')->where('company_id', $companyId)->where('status', 'active')->whereIn('role', ['owner', 'manager'])->pluck('user_id')->all();
        if ($company?->owner_id) {
            $ids[] = (int) $company->owner_id;
        }

        return User::withoutGlobalScopes()->whereIn('id', array_unique($ids))->get()->all();
    }

    public function notify(int $companyId, string $key, string $title, string $body, array $data = [], ?array $users = null): void
    {
        foreach ($users ?? $this->managers($companyId) as $user) {
            $pref = $this->preference((int) $user->id, $key);
            if (! $pref['enabled']) {
                continue;
            }
            if (in_array('in_app', $pref['channels'], true)) {
                DB::table('app_notifications')->insert(['company_id' => $companyId, 'user_id' => $user->id, 'type' => $key, 'title' => $title, 'body' => $body,
                    'data' => json_encode($data), 'created_at' => now(), 'updated_at' => now()]);
            }
            $out = array_values(array_intersect($pref['channels'], ['whatsapp', 'sms', 'mail']));
            if (! in_array($key, ['billing', 'team'], true) && ! $this->whatsappAutomation($companyId)) {
                $out = array_values(array_diff($out, ['whatsapp'])); // Free plan: WhatsApp automation is a paid feature (H2)
            }
            $phoneChannels = array_values(array_intersect($out, ['whatsapp', 'sms']));
            if ($phoneChannels !== [] && $user->phone_e164) {
                $this->messenger->send($user->phone_e164, "*{$title}*\n{$body}", $phoneChannels, ['company_id' => $companyId, 'user_id' => $user->id, 'purpose' => $key]);
            } elseif (in_array('mail', $out, true) && $user->email) {
                $this->messenger->send($user->email, "{$title}\n\n{$body}", ['mail'], ['company_id' => $companyId, 'user_id' => $user->id, 'purpose' => $key, 'options' => ['mail' => ['subject' => $title]]]);
            }
        }
    }

    private function whatsappAutomation(int $companyId): bool
    {
        $company = Company::withoutGlobalScopes()->find($companyId);

        return $company !== null && (new \App\Services\Billing\Quotas())->featureOn($company, 'whatsapp_automation');
    }

    public function welcome(User $user, Company $company): void
    {
        $text = 'Welcome to '.config('app.name').", {$user->first_name}! {$company->name} is ready. Add your products and make your first sale — everything works offline.";
        $to = $user->phone_e164 ?: $user->email;
        if ($to) {
            $this->messenger->send($to, $text, Messenger::channelsFor($user->phone_e164, $user->email), ['company_id' => $company->id, 'user_id' => $user->id, 'purpose' => 'welcome',
                'options' => ['mail' => ['subject' => 'Welcome to '.config('app.name')]]]);
        }
        DB::table('app_notifications')->insert(['company_id' => $company->id, 'user_id' => $user->id, 'type' => 'welcome', 'title' => 'Welcome!',
            'body' => 'Start with the getting-started checklist on your dashboard.', 'created_at' => now(), 'updated_at' => now()]);
    }
}
