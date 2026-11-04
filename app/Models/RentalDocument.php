<?php

namespace App\Models;

use App\Traits\RentalRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * A file kept on a tenant, lease, property or landlord (an ID copy, a signed agreement, a photo).
 */
class RentalDocument extends Model
{
    use RentalRecord;

    protected $table = 'rental_documents';

    protected $guarded = ['id'];
}
