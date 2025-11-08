<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\AuditLogger;

class HandoverRecord extends Model
{
    use HasFactory, AuditLogger;
}
