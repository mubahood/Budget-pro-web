<?php

namespace App\Models\Learn;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A heading on the Learn screen ("Getting started", "Money in"). Shared by every app; empty ones are not shown. */
class LearnSection extends Model
{
    protected $table = 'learn_sections';

    protected $guarded = ['id'];

    protected $casts = ['sort' => 'integer'];

    public function lessons(): HasMany
    {
        return $this->hasMany(LearnLesson::class, 'section_id');
    }
}
