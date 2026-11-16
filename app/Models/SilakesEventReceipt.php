<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Event dari SiLAKES yang sudah diproses (event_id unik), supaya kiriman ulang tidak dobel. */
class SilakesEventReceipt extends Model
{
    public $timestamps = false;

    protected $fillable = ['event_id', 'event', 'received_at'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}
