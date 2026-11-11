<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\HasSyncIdentity;

class Room extends Model
{
    use HasFactory, HasSyncIdentity;

    protected $fillable = [
        'center_id',
        'name',
        'capacity',
    ];

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

}
