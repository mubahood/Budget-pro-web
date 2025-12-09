<?php

namespace App\Models;

use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HandoverRecord extends Model
{
    use AuditLogger, HasFactory;
}
