<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A file of a memory (a `files` row; a photo also names its small picture). Reached only through its memory. */
class KinMemoryFile extends Model
{
    public $timestamps = false;

    protected $table = 'kin_memory_files';

    protected $guarded = ['id'];
}
