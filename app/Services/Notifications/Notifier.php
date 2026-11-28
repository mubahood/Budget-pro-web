<?php

namespace App\Services\Notifications;

use App\Models\Company;
use App\Models\User;
use App\Services\Messaging\Messenger;
use Illuminate\Support\Facades\DB;

/**
 * Business notifications (plan C7, P3-5): an in-app entry for the right people, a push to their phones (send(), engage plan §2)
 * and an outbound message on the channels each person chose.
 */
class Notifier
{
    /**
     * key => [label, default channels, default on?, apps it belongs to (null: every app), Android channel].
     * Labels never name an app: a SACCO or a family sees only its own kinds (forApp()).
     */
    public const TYPES = [
        'daily_summary' => ['Daily sales summary (8 pm)', ['in_app', 'push'], true, ['shelfie', 'budget-pro'], 'activity'],
        'low_stock' => ['Low stock digest', ['in_app', 'push'], true, ['shelfie', 'budget-pro'], 'activity'],
        'big_sale' => ['A big sale', ['in_app', 'push'], true, ['shelfie', 'budget-pro'], 'activity'],
        'unsynced_device' => ['A phone has not synced for 3 days', ['whatsapp', 'in_app', 'push'], true, null, 'account'],
        'cash_variance' => ['Cash-up difference', ['in_app', 'push'], true, ['shelfie', 'budget-pro'], 'activity'],
        'billing' => ['Plan and payment reminders', ['whatsapp', 'mail', 'in_app', 'push'], true, null, 'account'],
        'team' => ['Team changes', ['in_app', 'push'], true, null, 'account'],
        'fiscal' => ['Receipts that could not be fiscalised', ['in_app', 'push'], true, ['shelfie', 'budget-pro'], 'account'],
        'app_update' => ['A new version of the app', ['in_app', 'push'], true, null, 'account'],
        'weekly_digest' => ['Your week (Sunday evening)', ['in_app', 'push'], true, null, 'activity'],
        'poultry' => ['Flock alerts: deaths, feed and vaccinations', ['in_app', 'push'], true, ['kuku-tally', 'budget-pro'], 'reminders'],
        'pledges' => ['Pledges made and paid', ['in_app', 'push'], true, ['pledge-and-budget', 'budget-pro'], 'activity'],
        'budget' => ['Budget lines overspent and event days', ['in_app', 'push'], true, ['pledge-and-budget', 'budget-pro'], 'reminders'],
        'sacco_money' => ['Deposits and repayments recorded', ['in_app', 'push'], true, ['akiba-ledger'], 'activity'],
        'sacco_loans' => ['Loan decisions', ['in_app', 'push'], true, ['akiba-ledger'], 'activity'],
        'loan_due' => ['Loan repayments due and overdue', ['in_app', 'push'], true, ['akiba-ledger'], 'reminders'],
        'sacco_dividends' => ['Dividends posted', ['in_app', 'push'], true, ['akiba-ledger'], 'activity'],
        'sacco_returns' => ['Returns due', ['in_app', 'push'], true, ['akiba-ledger'], 'reminders'],
        'rent_due' => ['Rent due and overdue', ['in_app', 'push'], true, ['estate-ledger'], 'reminders'],
        'rent_paid' => ['Rent payments recorded', ['in_app', 'push'], true, ['estate-ledger'], 'activity'],
        'leases' => ['Leases ending', ['in_app', 'push'], true, ['estate-ledger'], 'reminders'],
        'maintenance' => ['Repair requests opened and closed', ['in_app', 'push'], true, ['estate-ledger'], 'activity'],
        'kin_dates' => ['Birthdays and remembrance days', ['in_app', 'push'], true, ['family-roots'], 'reminders'],
        'kin_people' => ['New people in your branch', ['in_app', 'push'], true, ['family-roots'], 'activity'],
        'kin_announcement' => ['Family announcements', ['in_app', 'push'], true, ['family-roots'], 'activity'],
        'kin_event' => ['Gatherings planned', ['in_app', 'push'], true, ['family-roots'], 'activity'],
        'unity_due' => ['Subscriptions due and overdue', ['in_app', 'push'], true, ['unitydesk'], 'reminders'],
        'unity_paid' => ['Payments received', ['in_app', 'push'], true, ['unitydesk'], 'activity'],
        'hide_details' => ['Hide amounts and names on the lock screen', [], false, null, 'account'],
    ];

    /** Pushes a person gets from one app in one (Kampala) day; more go to the inbox only. */
    public const DAILY_PUSH_CAP = 3;

    /** Quiet hours (Africa/Kampala): a push due now waits until the end, unless urgent. */
    public const QUIET_FROM = 21;

    public const QUIET_UNTIL = 7;

    public const TZ = 'Africa/Kampala';

    public function __construct(private readonly Messenger $messenger = new Messenger(), private ?PushSender $push = null)
    {
    }

    /** The kinds a phone of this app shows in its settings (all of them when the app is unknown). @return array<string, array> */
    public static function forApp(?string $app): array
    {
        return array_filter(self::TYPES, fn ($t) => $app === null || $t[3] === null || in_array($app, $t[3], true));
    }

    /** @return array{enabled: bool, channels: array<int, string>} */
    public function preference(int $userId, string $key): array
    {
        $row = DB::table('notification_preferences')->where('user_id', $userId)->where('key', $key)->first();
        [$label, $channels, $on] = self::TYPES[$key] ?? ['', ['in_app', 'push'], true];

        return $row ? ['enabled' => (bool) $row->enabled, 'channels' => json_decode($row->channels, true) ?: []] : ['enabled' => $on, 'channels' => $channels];
    }

    public function setPreference(int $userId, string $key, bool $enabled, array $channels): void
    {
        DB::table('notification_preferences')->updateOrInsert(['user_id' => $userId, 'key' => $key],
            ['enabled' => $enabled, 'channels' => json_encode(array_values(array_intersect($channels, ['whatsapp', 'sms', 'mail', 'in_app', 'push']))), 'updated_at' => now(), 'created_at' => now()]);
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

    /** Everyone with a sign-in to the account: the owner and the active team. @return array<int, User> */
    public function everyone(int $companyId): array
    {
        $company = Company::withoutGlobalScopes()->find($companyId);
        $ids = DB::table('company_members')->where('company_id', $companyId)->where('status', 'active')->pluck('user_id')->all();
        if ($company?->owner_id) {
            $ids[] = (int) $company->owner_id;
        }

        return User::withoutGlobalScopes()->whereIn('id', array_unique($ids))->get()->all();
    }

    /**
     * The managers (or $users) of an account, each by the channels they chose: the inbox and a push (send()), then WhatsApp/SMS/mail.
     * $link opens the right screen on a tap ("<area>/<id>"), $app names the app when the account's books are shared, $dedupe makes
     * the same message go once per person.
     */
    public function notify(int $companyId, string $key, string $title, string $body, array $data = [], ?array $users = null,
        ?string $link = null, ?string $app = null, ?string $dedupe = null, bool $urgent = false): void
    {
        foreach ($users ?? $this->managers($companyId) as $user) {
            if ($user === null) {
                continue;
            }
            $pref = $this->preference((int) $user->id, $key);
            if (! $pref['enabled']) {
                continue;
            }
            if (array_intersect(['in_app', 'push'], $pref['channels']) !== []) {
                if ($this->deliver($user, $companyId, $app, $key, $title, $body, $link, $urgent, $dedupe, $data, $pref['channels']) === null && $dedupe !== null) {
                    continue; // sent before: no second WhatsApp either
                }
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

    /**
     * One notification to one person (engage plan §2): the inbox row, then a push to their phones of that app.
     * Rules: their preference for the kind; quiet hours 21:00-07:00 Kampala unless urgent (the push waits, it is not dropped); at most
     * DAILY_PUSH_CAP pushes a day per person per app (more stay in the inbox); the same $dedupe once only; "hide details" sends a
     * plain body. Returns the inbox row's id, or null when the person turned the kind off or it was sent before.
     */
    public function send(User $user, ?string $app, string $kind, string $title, string $body, ?string $link = null, bool $urgent = false,
        ?int $companyId = null, ?string $dedupe = null, array $data = []): ?int
    {
        $pref = $this->preference((int) $user->id, $kind);
        if (! $pref['enabled']) {
            return null;
        }

        return $this->deliver($user, $companyId ?? (int) $user->company_id, $app, $kind, $title, $body, $link, $urgent, $dedupe, $data, $pref['channels']);
    }

    private function deliver(User $user, int $companyId, ?string $app, string $kind, string $title, string $body, ?string $link, bool $urgent,
        ?string $dedupe, array $data, array $channels): ?int
    {
        $company = Company::withoutGlobalScopes()->find($companyId);
        $app = \App\Support\AppFamily::slug($app) ?? ($company ? \App\Support\StoreLink::appOf(null, $company) : null) ?? (self::TYPES[$kind][3][0] ?? null);
        $wantsPush = in_array('push', $channels, true) && ! ($company?->is_demo); // a demo's shared logins get no pushes
        $now = now();
        $row = ['company_id' => $companyId, 'user_id' => $user->id, 'app' => $app, 'type' => $kind, 'title' => mb_substr($title, 0, 191), 'body' => $body,
            'link' => $link !== null ? mb_substr($link, 0, 191) : null, 'data' => json_encode($data + ($link !== null ? ['link' => $link] : [])),
            'push_status' => $wantsPush ? 'pending' : null, 'push_due_at' => $wantsPush ? ($urgent ? $now : self::afterQuietHours($now)) : null,
            'urgent' => $urgent, 'created_at' => $now, 'updated_at' => $now];
        if ($dedupe !== null) {
            $key = 'u'.$user->id.':'.($app ?? '-').':'.$dedupe;
            $row['dedupe_key'] = strlen($key) > 120 ? 'h:'.sha1($key) : $key;
            if (DB::table('app_notifications')->insertOrIgnore($row) === 0) {
                return null;
            }
            $id = (int) DB::table('app_notifications')->where('dedupe_key', $row['dedupe_key'])->value('id');
        } else {
            $id = (int) DB::table('app_notifications')->insertGetId($row);
        }
        if ($wantsPush && $row['push_due_at'] <= $now) {
            $this->pushNow($id);
        }

        return $id;
    }

    /** The moment a push may go: now, or 07:00 Kampala when now is in the quiet hours. */
    public static function afterQuietHours(\Illuminate\Support\Carbon $at): \Illuminate\Support\Carbon
    {
        $local = $at->copy()->setTimezone(self::TZ);
        if ($local->hour >= self::QUIET_FROM) {
            return $local->addDay()->setTime(self::QUIET_UNTIL, 0)->setTimezone(config('app.timezone'));
        }
        if ($local->hour < self::QUIET_UNTIL) {
            return $local->setTime(self::QUIET_UNTIL, 0)->setTimezone(config('app.timezone'));
        }

        return $at;
    }

    /** Apps whose phones get a push meant for $app: a family app's books also open in Budget Pro. @return array<int, string> */
    private static function phoneApps(?string $app): ?array
    {
        if ($app === null) {
            return null;
        }

        return \App\Support\AppFamily::ownBooks($app) || $app === 'budget-pro' ? [$app] : [$app, 'budget-pro'];
    }

    /** Push one pending inbox row to the person's phones now. Returns its push_status. */
    public function pushNow(int $id): ?string
    {
        // Claim it, so two runs never push the same row twice.
        if (DB::table('app_notifications')->where('id', $id)->where('push_status', 'pending')->update(['push_status' => 'sending', 'updated_at' => now()]) === 0) {
            return null;
        }
        $n = DB::table('app_notifications')->find($id);
        $user = User::withoutGlobalScopes()->find($n->user_id);
        $finish = function (string $status) use ($id): string {
            DB::table('app_notifications')->where('id', $id)->update(['push_status' => $status, 'pushed_at' => $status === 'sent' ? now() : null, 'updated_at' => now()]);

            return $status;
        };
        if ($user === null) {
            return $finish('skipped');
        }
        $apps = self::phoneApps($n->app);
        $devices = \App\Models\Device::withoutGlobalScopes()->where('user_id', $user->id)->where('company_id', $n->company_id)
            ->whereNotNull('push_token')->where('status', '!=', 'revoked')
            ->when($apps !== null, fn ($q) => $q->whereIn('app', $apps))
            // Only a phone still signed in: a sign-in that was ended or ran out takes its pushes with it.
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('personal_access_tokens')->whereColumn('personal_access_tokens.id', 'devices.access_token_id')
                ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now())))
            ->get();
        if ($devices->isEmpty()) {
            return $finish('skipped');
        }
        if (! $n->urgent && self::pushedToday((int) $user->id, $n->app) >= self::DAILY_PUSH_CAP) {
            return $finish('skipped');
        }
        $hide = $this->preference((int) $user->id, 'hide_details')['enabled'];
        $sender = $this->push ??= app(PushSender::class);
        $results = [];
        foreach ($devices as $device) {
            $app = $device->app ?: $n->app;
            [$title, $body] = $hide ? [$app ? \App\Support\AppFamily::name($app) : 'New notification', 'You have a new notification. Open the app to see it.'] : [$n->title, (string) $n->body];
            $result = $sender->send((string) $device->push_token, $title, $body, ['kind' => $n->type, 'link' => $n->link ?: 'notifications', 'notification_id' => $id, 'app' => $app],
                self::TYPES[$n->type][4] ?? 'activity', (bool) $n->urgent);
            if ($result === PushSender::BAD_TOKEN) {
                \App\Models\Device::withoutGlobalScopes()->where('id', $device->id)->where('push_token', $device->push_token)
                    ->update(['push_token' => null, 'push_provider' => null, 'access_token_id' => null, 'push_token_at' => null]);
            }
            $results[] = $result;
        }

        return $finish(in_array(PushSender::SENT, $results, true) ? 'sent' : (array_diff($results, [PushSender::OFF, PushSender::BAD_TOKEN]) === [] ? 'skipped' : 'failed'));
    }

    /** Pushes this person got from this app today (Kampala day). */
    public static function pushedToday(int $userId, ?string $app): int
    {
        $from = now()->setTimezone(self::TZ)->startOfDay()->setTimezone(config('app.timezone'));

        return DB::table('app_notifications')->where('user_id', $userId)->where('push_status', 'sent')->where('pushed_at', '>=', $from)
            ->when($app !== null, fn ($q) => $q->where('app', $app), fn ($q) => $q->whereNull('app'))->count();
    }

    /** Pushes whose quiet hours are over (notifications:push, every 20 minutes). Returns how many went. */
    public function pushDue(int $limit = 300): int
    {
        $sent = 0;
        // Nobody wants yesterday's news at 7 in the morning in bulk: a push that waited more than a day stays in the inbox only.
        DB::table('app_notifications')->where('push_status', 'pending')->where('push_due_at', '<', now()->subDay())->update(['push_status' => 'skipped']);
        // A run that died mid-push leaves "sending": after an hour it is tried once more.
        DB::table('app_notifications')->where('push_status', 'sending')->where('updated_at', '<', now()->subHour())->update(['push_status' => 'pending']);
        foreach (DB::table('app_notifications')->where('push_status', 'pending')->where('push_due_at', '<=', now())->orderBy('id')->limit($limit)->pluck('id') as $id) {
            $sent += (int) ($this->pushNow((int) $id) === 'sent');
        }

        return $sent;
    }

    private function whatsappAutomation(int $companyId): bool
    {
        $company = Company::withoutGlobalScopes()->find($companyId);

        return $company !== null && (new \App\Services\Billing\Quotas())->featureOn($company, 'whatsapp_automation');
    }

    public function welcome(User $user, Company $company): void
    {
        if (in_array('kin', $company->modules(), true)) {
            // My Family Roots 360 stands alone (even with a purse): its own name and first steps, its own footer.
            $brand = (string) config('kin.brand.name', 'My Family Roots 360');
            $to = $user->phone_e164 ?: $user->email;
            if ($to) {
                $this->messenger->send($to, "Welcome to {$brand}, {$user->first_name}! {$company->name} is ready: add yourself, your parents and your children, then invite your family.",
                    Messenger::channelsFor($user->phone_e164, $user->email), ['company_id' => $company->id, 'user_id' => $user->id, 'purpose' => 'welcome',
                        'options' => ['mail' => ['subject' => 'Welcome to '.$brand, 'kin' => true]]]);
            }
            DB::table('app_notifications')->insert(['company_id' => $company->id, 'user_id' => $user->id, 'type' => 'welcome', 'title' => 'Welcome!',
                'body' => 'Start your family tree with yourself and your parents.', 'created_at' => now(), 'updated_at' => now()]);

            return;
        }
        if (in_array('unity', $company->modules(), true)) {
            // UnityDesk stands alone: its own name and first steps, its own footer, no other app named.
            $brand = (string) config('unity.brand.name', 'UnityDesk');
            $to = $user->phone_e164 ?: $user->email;
            if ($to) {
                $this->messenger->send($to, "Welcome to {$brand}, {$user->first_name}! {$company->name} is ready: set your membership categories, then add your members.",
                    Messenger::channelsFor($user->phone_e164, $user->email), ['company_id' => $company->id, 'user_id' => $user->id, 'purpose' => 'welcome',
                        'options' => ['mail' => ['subject' => 'Welcome to '.$brand, 'unity' => true]]]);
            }
            DB::table('app_notifications')->insert(['company_id' => $company->id, 'user_id' => $user->id, 'type' => 'welcome', 'title' => 'Welcome!',
                'body' => 'Start by setting your membership categories and fees.', 'created_at' => now(), 'updated_at' => now()]);

            return;
        }
        if (in_array('sacco', $company->modules(), true)) {
            // Akiba Ledger stands alone (the owner's rule): its own name, its own first steps, no family footer.
            $to = $user->phone_e164 ?: $user->email;
            if ($to) {
                $this->messenger->send($to, "Welcome to Akiba Ledger, {$user->first_name}! {$company->name} is ready: set up the SACCO's books, then register your members.",
                    Messenger::channelsFor($user->phone_e164, $user->email), ['company_id' => $company->id, 'user_id' => $user->id, 'purpose' => 'welcome',
                        'options' => ['mail' => ['subject' => 'Welcome to Akiba Ledger', 'akiba' => true]]]);
            }
            DB::table('app_notifications')->insert(['company_id' => $company->id, 'user_id' => $user->id, 'type' => 'welcome', 'title' => 'Welcome!',
                'body' => 'Start by setting up your SACCO\'s books.', 'created_at' => now(), 'updated_at' => now()]);

            return;
        }
        if (in_array('rentals', $company->modules(), true)) {
            // Estate Ledger stands alone (KR-05): its own name and first steps, its own footer.
            $to = $user->phone_e164 ?: $user->email;
            if ($to) {
                $this->messenger->send($to, "Welcome to Estate Ledger, {$user->first_name}! {$company->name} is ready: add your properties and units, then move your tenants in.",
                    Messenger::channelsFor($user->phone_e164, $user->email), ['company_id' => $company->id, 'user_id' => $user->id, 'purpose' => 'welcome',
                        'options' => ['mail' => ['subject' => 'Welcome to Estate Ledger', 'estate-ledger' => true]]]);
            }
            DB::table('app_notifications')->insert(['company_id' => $company->id, 'user_id' => $user->id, 'type' => 'welcome', 'title' => 'Welcome!',
                'body' => 'Start by adding your first property and its units.', 'created_at' => now(), 'updated_at' => now()]);

            return;
        }
        $text = 'Welcome to '.config('app.name').", {$user->first_name}! {$company->name} is ready. Add your products and make your first sale — everything works offline.";
        $to = $user->phone_e164 ?: $user->email;
        if ($to) {
            $this->messenger->send($to, $text, Messenger::channelsFor($user->phone_e164, $user->email), ['company_id' => $company->id, 'user_id' => $user->id, 'purpose' => 'welcome',
                // The email ends with "WhatsApp us to set up" (SupportContact, trial.welcome): the shop's name only.
                'options' => ['mail' => ['subject' => 'Welcome to '.config('app.name'), 'support_context' => 'trial.welcome', 'support_vars' => ['shop' => (string) $company->name]]]]);
        }
        DB::table('app_notifications')->insert(['company_id' => $company->id, 'user_id' => $user->id, 'type' => 'welcome', 'title' => 'Welcome!',
            'body' => 'Start with the getting-started checklist on your dashboard.', 'created_at' => now(), 'updated_at' => now()]);
    }
}
