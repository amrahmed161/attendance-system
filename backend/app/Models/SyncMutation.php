<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncMutation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'mutation_uuid',
        'payload_hash',
        'receipt',
        'created_at',
    ];

    protected $casts = [
        'receipt' => 'array',
        'created_at' => 'immutable_datetime',
    ];
}
