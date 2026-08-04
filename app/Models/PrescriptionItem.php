<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrescriptionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'prescription_id',
        'medicine_name',
        'dosage',
        'dosage_schedule',
        'instructions',
        'duration_days',
    ];
    protected $casts = [
        'dosage_schedule' => 'array', // Automatically casts JSON string to PHP array
        'duration_days' => 'integer',
    ];
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class, 'prescription_id');
    }
}
