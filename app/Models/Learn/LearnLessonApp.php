<?php

namespace App\Models\Learn;

use Illuminate\Database\Eloquent\Model;

/** One app a lesson is for (AppFamily slug). */
class LearnLessonApp extends Model
{
    public $timestamps = false;

    protected $table = 'learn_lesson_apps';

    protected $guarded = ['id'];
}
