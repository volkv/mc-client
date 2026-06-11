<?php

namespace Volkv\McClient\Models;

use Eloquent;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin Eloquent
 */
class McCount extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'mc_counts';
}
