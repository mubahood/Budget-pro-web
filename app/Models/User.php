<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Traits\AuditLogger;
use Encore\Admin\Auth\Database\Administrator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * User Model
 *
 * Represents a system user with admin capabilities.
 * Extends Encore Admin's Administrator model.
 *
 * @property int $id
 * @property string $username
 * @property string $password
 * @property string $name
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string|null $avatar
 * @property int|null $company_id
 * @property string|null $phone_number
 * @property string|null $phone_e164
 * @property \Illuminate\Support\Carbon|null $phone_verified_at
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property string|null $locale
 * @property string|null $status
 * @property string|null $address
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Company|null $company
 */
class User extends Administrator
{
    use AuditLogger, HasApiTokens, HasFactory, Notifiable;

    protected $table = 'admin_users';

    /**
     * Get the company this user belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * Boot method for model events.
     * Handles automatic name generation and role assignment.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $name = '';
            if ($model->first_name != null && strlen($model->first_name) > 0) {
                $name = $model->first_name;
            }
            if ($model->last_name != null && strlen($model->last_name) > 0) {
                $name .= ' '.$model->last_name;
            }
            $name = trim($name);

            if ($name != null && strlen($name) > 0) {
                $model->name = $name;
            }
            // Username defaults to the email (or phone for phone-only Ping Pin accounts) but is
            // never clobbered when one was given explicitly (P0-13).
            if (empty($model->username)) {
                $model->username = $model->email ?: $model->phone_number;
            }

            // Never silently create a login with a guessable password (P0-2).
            if ($model->password == null || strlen($model->password) < 3) {
                throw new \App\Exceptions\BusinessRuleException('A password is required to create a user account.');
            }

            return $model;
        });

        static::updating(function ($model) {
            $name = '';
            if ($model->first_name != null && strlen($model->first_name) > 0) {
                $name = $model->first_name;
            }
            if ($model->last_name != null && strlen($model->last_name) > 0) {
                $name .= ' '.$model->last_name;
            }
            $name = trim($name);

            if ($name != null && strlen($name) > 0) {
                $model->name = $name;
            }
            // Follow an email change only when the username was the old email (or is empty).
            if ($model->isDirty('email') && (empty($model->username) || $model->username === $model->getOriginal('email'))) {
                $model->username = $model->email ?: $model->phone_number;
            }

            return $model;
        });

        // Ensure company owners always have role ID 2
        static::created(function ($model) {
            self::ensureCompanyOwnerRole($model);
        });

        static::updated(function ($model) {
            self::ensureCompanyOwnerRole($model);
        });
    }

    /**
     * Ensure that company owners have the Company Owner role (ID 2)
     * This runs automatically on user creation and updates
     */
    public static function ensureCompanyOwnerRole($user)
    {
        // Skip if user doesn't have a company_id yet
        if (empty($user->company_id)) {
            return;
        }

        // Check if this user is the owner of their company
        $company = \App\Models\Company::where('id', $user->company_id)
            ->where('owner_id', $user->id)
            ->first();

        if ($company) {
            // This user IS a company owner - ensure they have role ID 2
            $companyOwnerRoleId = 2;

            // Check if role assignment already exists
            $existingRole = \DB::table('admin_role_users')
                ->where('user_id', $user->id)
                ->where('role_id', $companyOwnerRoleId)
                ->first();

            if (! $existingRole) {
                // Role not assigned yet - assign it now
                \DB::table('admin_role_users')->insert([
                    'role_id' => $companyOwnerRoleId,
                    'user_id' => $user->id,
                ]);

                \Log::info('Company owner role auto-assigned via User model', [
                    'user_id' => $user->id,
                    'company_id' => $user->company_id,
                    'role_id' => $companyOwnerRoleId,
                ]);
            }
        }
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];
}
