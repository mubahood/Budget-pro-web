<?php

namespace App\Admin\Controllers;

use App\Support\Rules\PasswordPolicy;
use Encore\Admin\Controllers\UserController;
use Encore\Admin\Form;

/**
 * laravel-admin's platform user screen (auth/users, platform admins only) with the one password rule
 * and its hint (App\Support\Rules\PasswordPolicy). A changed password signs that person out of the
 * phone app everywhere. Registered over Encore's own route in app/Admin/routes.php.
 */
class PlatformUserController extends UserController
{
    public function form()
    {
        $form = parent::form();
        $field = $form->builder()->field('password');
        if ($field !== null) {
            // A closure replaces Encore's rules: the stored hash sent back unchanged is not a new password.
            $field->rules(function (Form $form) {
                $id = request()->route('user');
                $current = $id ? (string) \App\Models\User::withoutGlobalScopes()->whereKey((int) $id)->value('password') : null;

                return PasswordPolicy::formRules(request('password'), $current);
            })->help(PasswordPolicy::hint());
        }

        $changed = false;
        $form->saving(function (Form $form) use (&$changed) {
            // Runs after Encore's own saving callback, which already hashed a new password.
            $changed = $form->isEditing() && $form->password && $form->model()->password != $form->password;
        });
        $form->saved(function (Form $form) use (&$changed) {
            if ($changed && method_exists($form->model(), 'tokens')) {
                $form->model()->tokens()->delete();
            }
        });

        return $form;
    }
}
