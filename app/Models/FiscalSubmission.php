<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One sale (or return, as a credit note) sent to a fiscal adapter (supermarket plan F2). Written by FiscalService only. */
class FiscalSubmission extends Model
{
    public const STATUSES = ['pending' => 'Waiting', 'sent' => 'Fiscalised', 'failed' => 'Failed', 'skipped' => 'Skipped'];

    protected $table = 'fiscal_submissions';

    protected $guarded = ['id'];

    protected $hidden = ['raw_request', 'raw_response'];

    protected $casts = ['next_attempt_at' => 'datetime', 'last_attempt_at' => 'datetime', 'sent_at' => 'datetime', 'owner_notified' => 'boolean'];

    public function sale()
    {
        return $this->belongsTo(SaleRecord::class, 'sale_record_id')->withoutGlobalScopes();
    }
}
