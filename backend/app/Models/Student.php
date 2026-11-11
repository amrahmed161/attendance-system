<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\HasSyncIdentity;

class Student extends Model
{
    use HasFactory, HasSyncIdentity;

    protected $fillable = [
        'name',
        'student_phone',
        'parent_phone',
        'grade_level',
        'center_id',
        'barcode',
    ];

    protected $casts = [
        'grade_level' => 'string',
    ];

    protected static function booted(): void
    {
        static::created(function (Student $student) {
            if (! $student->barcode) {
                $usedNumbers = [];

                static::query()
                    ->whereNotNull('barcode')
                    ->pluck('barcode')
                    ->each(function (string $barcode) use (&$usedNumbers) {
                        if (preg_match('/^(?:STU-)?(\d+)$/', $barcode, $matches)) {
                            $usedNumbers[(int) $matches[1]] = true;
                        }
                    });

                $number = 1;
                while (isset($usedNumbers[$number])) {
                    $number++;
                }

                $student->forceFill([
                    'barcode' => (string) $number,
                ])->saveQuietly();
            }
        });
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

}
