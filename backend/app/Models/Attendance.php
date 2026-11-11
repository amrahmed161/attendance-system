<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\HasSyncIdentity;

class Attendance extends Model
{
    use HasFactory, HasSyncIdentity;

    protected $fillable = [
        'student_id',
        'date',
        'status',
        'absence_reason',
        'recorded_by',
    ];

    protected $casts = [
        'date' => 'date',
        'status' => 'string',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
