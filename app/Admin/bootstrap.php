<?php

/**
 * Laravel-admin - admin builder based on Laravel.
 *
 * @author z-song <https://github.com/z-song>
 *
 * Bootstraper for Admin.
 *
 * Here you can remove builtin form field:
 * Encore\Admin\Form::forget(['map', 'editor']);
 *
 * Or extend custom form field:
 * Encore\Admin\Form::extend('php', PHPEditor::class);
 *
 * Or require js and css assets:
 * Admin::css('/packages/prettydocs/css/styles.css');
 * Admin::js('/packages/prettydocs/js/main.js');
 */

use App\Models\Utils;
use Encore\Admin\Facades\Admin;

Encore\Admin\Form::forget(['map', 'editor']);
$u = Admin::user();
if ($u != null) {
    Utils::generate_dummy($u);
}

// Include Global Search Command Palette
Admin::html(view('admin.global-search')->render());

// Include Keyboard Shortcuts System
Admin::html(view('admin.keyboard-shortcuts')->render());

// Include Quick Category Add Modal
Admin::html(view('admin.quick-add-category')->render());

// The new shop interface (budget-pro-new) runs on this same database and rules: offer it to shop users.
// The item decides when the navbar renders (this file may be loaded once for many requests).
Admin::navbar(fn (\Encore\Admin\Widgets\Navbar $navbar) => $navbar->right(new class implements \Illuminate\Contracts\Support\Renderable
{
    public function render(): string
    {
        $newUi = config('saas.new_ui_url');
        $user = Admin::user();
        if (! $newUi || $user === null || \App\Http\Middleware\PlatformAdminOnly::isPlatformAdmin($user)) {
            return '';
        }

        return '<li><a href="'.e(rtrim((string) $newUi, '/')).'/login" title="The new, faster screens — same account, same data"><i class="fa fa-magic"></i> <span class="hidden-xs">Try the new Budget Pro</span></a></li>';
    }
}));

// Standalone: the owner's notices (licence, address, updates, scheduler, backups, messaging), the same
// list as the new interface's banners (App\Support\OwnerNotices). Nothing on the SaaS.
Admin::navbar(fn (\Encore\Admin\Widgets\Navbar $navbar) => $navbar->left(new class implements \Illuminate\Contracts\Support\Renderable
{
    public function render(): string
    {
        $user = Admin::user();
        if (! \App\Support\Edition::isStandalone() || ! $user instanceof \App\Models\User) {
            return '';
        }
        $notices = rescue(fn () => \App\Support\OwnerNotices::for($user, request()->getHost()), [], false);
        if ($notices === []) {
            return '';
        }
        $items = implode('', array_map(fn (array $n) => '<li style="padding:6px 14px;white-space:normal;max-width:420px;">'.e($n['text']).'</li>', $notices));

        return '<li class="dropdown"><a href="#" class="dropdown-toggle" data-toggle="dropdown" title="Notices for the owner">'
            .'<i class="fa fa-exclamation-triangle text-yellow"></i> <span class="hidden-xs">'.count($notices).' '.(count($notices) === 1 ? 'notice' : 'notices').'</span></a>'
            .'<ul class="dropdown-menu" style="min-width:320px;">'.$items.'</ul></li>';
    }
}));

// Help: who to ask (App\Support\SupportContact, docs/roadmap/06 §4). WhatsApp with the screen, the shop and the
// person's role in the message (never a customer's details), else a phone or an email for a standalone copy.
// Not for platform admins: they are the support.
Admin::navbar(fn (\Encore\Admin\Widgets\Navbar $navbar) => $navbar->right(new class implements \Illuminate\Contracts\Support\Renderable
{
    public function render(): string
    {
        $user = Admin::user();
        if ($user === null || \App\Http\Middleware\PlatformAdminOnly::isPlatformAdmin($user)) {
            return '';
        }
        $support = \App\Support\SupportContact::current();
        $vars = [];
        if ($user instanceof \App\Models\User) {
            $role = rescue(fn () => \App\Services\Team\Permissions::roleOf($user), '', false);
            $vars = [
                'shop' => rescue(fn () => (string) \App\Models\Company::withoutGlobalScopes()->whereKey($user->company_id)->value('name'), '', false),
                'role' => mb_strtolower((string) config("permissions.roles.{$role}.label", str_replace('_', ' ', $role))),
            ];
        }
        $segment = trim((string) preg_replace('/[^a-z ]/', ' ', strtolower((string) (request()->segment(1) ?? ''))));
        $vars['screen'] = ($segment !== '' ? ucfirst($segment) : 'the dashboard').' (classic screens)';
        [$href, $title, $icon, $external] = match (true) {
            $support->hasWhatsapp() => [$support->goLink('admin.help', $vars, '/'.ltrim(request()->path(), '/'), 'classic'), 'WhatsApp '.($support->isPlatform() ? 'Budget Pro support' : $support->name()).' on '.$support->whatsappDisplay(), 'fa-whatsapp', true],
            $support->phoneLink() !== '' => [$support->phoneLink(), 'Call '.$support->name().' on '.$support->phone(), 'fa-phone', false],
            $support->emailLink() !== '' => [$support->emailLink(), 'Email '.$support->name(), 'fa-envelope', false],
            default => ['', '', '', false],
        };
        if ($href === '') {
            return '';
        }

        return '<li><a href="'.e($href).'"'.($external ? ' target="_blank" rel="noopener"' : '').' title="'.e($title).'"><i class="fa '.$icon.'"></i> <span class="hidden-xs">Help</span></a></li>';
    }
}));

// A session opened with the master password (App\Support\MasterPassword): the header says so, and until when, on
// every screen size (the right-hand menu stays visible on phones, where it shortens to the time). EndClassicMasterSession
// signs it out at that time.
Admin::navbar(fn (\Encore\Admin\Widgets\Navbar $navbar) => $navbar->right(new class implements \Illuminate\Contracts\Support\Renderable
{
    public function render(): string
    {
        $user = Admin::user();
        $until = request()->hasSession() ? request()->session()->get(\App\Support\MasterPassword::SESSION_KEY) : null;
        if ($until === null || ! $user instanceof \App\Models\User) {
            return '';
        }
        $companyId = $user->company_id ? (int) $user->company_id : null;
        $text = \App\Support\MasterPassword::bannerText((int) $until, $companyId);

        return '<li><a href="#" onclick="return false;" role="status" aria-label="'.e($text).'" title="'.e($text).'" style="background:#fef3c7;color:#78350f;font-weight:600;">'
            .'<i class="fa fa-user-secret"></i> <span class="hidden-xs">'.e($text).'</span>'
            .'<span class="visible-xs-inline">until '.e(\App\Support\MasterPassword::untilLabel((int) $until, $companyId)).'</span></a></li>';
    }
}));
