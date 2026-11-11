<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SyncChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'entity_type',
        'entity_uuid',
        'operation',
        'entity_version',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'entity_version' => 'integer',
        'payload' => 'array',
        'created_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Change log records are append-only.'));
        static::deleting(fn () => throw new LogicException('Change log records are append-only.'));
    }
}
