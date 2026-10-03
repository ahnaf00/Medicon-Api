<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicalRecord extends Model
{
    use HasFactory;
    protected $fillable = [
        'patient_user_id',
        'recorded_by_user_id',
        'title',
        'laboratory_name',
        'report_date',
        'blood_pressure',
        'pulse_rate',
        'glucose_level',
        'oxygen_saturation',
        'file_url',
        'file_path',
        'notes',
        'analysis_status',
        'analysis_error',
        'ai_summary',
        'analyzed_at',
    ];
    protected $attributes = [
        'analysis_status' => 'pending',
    ];
    protected $casts = [
        'pulse_rate' => 'integer',
        'glucose_level' => 'decimal:2',
        'oxygen_saturation' => 'integer',
        'report_date' => 'date',
        'analyzed_at' => 'datetime',
    ];
    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_user_id');
    }
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
    public function pages(): HasMany
    {
        return $this->hasMany(MedicalRecordPage::class)->orderBy('page_order');
    }
    public function labResults(): HasMany
    {
        return $this->hasMany(LabResult::class)->orderBy('order');
    }
}
