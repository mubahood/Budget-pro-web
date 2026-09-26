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
