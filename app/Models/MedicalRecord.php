<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalRecord extends Model
{
    use HasFactory;
    protected $fillable = [
        'patient_user_id',
        'recorded_by_user_id',
        'blood_pressure',
        'pulse_rate',
        'glucose_level',
        'oxygen_saturation',
        'file_url',
        'notes',
    ];
    protected $casts = [
        'pulse_rate' => 'integer',
        'glucose_level' => 'decimal:2',
        'oxygen_saturation' => 'integer',
    ];
    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_user_id');
    }
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
