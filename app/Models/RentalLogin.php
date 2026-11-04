<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * A tenant's or landlord's own sign-in to the Estate Ledger portal and app (KR-16): never a staff login. One row per person
 * per account; the same email may open several accounts, and the person picks one. Tokens carry `rentals-portal`.
 */
class RentalLogin extends Model
{
    use HasApiTokens, RentalRecord;

    public const SUBJECTS = ['tenant', 'landlord'];

    public const ABILITY = 'rentals-portal';

    protected $table = 'rental_logins';

    protected $guarded = ['id'];

    protected $hidden = ['password'];

    protected $casts = ['failures' => 'integer', 'locked_until' => 'datetime', 'disabled_at' => 'datetime', 'last_login_at' => 'datetime'];

    /** The tenant or landlord this sign-in opens (scoped to its own account). */
    public function subject(): RentalTenant|RentalLandlord|null
    {
        $class = $this->subject_type === 'landlord' ? RentalLandlord::class : RentalTenant::class;

        return $class::withoutGlobalScopes()->where('company_id', $this->company_id)->find($this->subject_id);
    }
}
