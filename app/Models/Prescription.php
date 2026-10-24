<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prescription extends Model
{
    use HasFactory;

    protected $fillable = [
        'appointment_id',
        'patient_user_id',
        'doctor_user_id',
        'diagnosis_summary',
        'follow_up_date',
        'advice',
        'status',
        'replaces_prescription_id',
        'allergy_conflicts',
        'allergy_acknowledged_at',
    ];

    protected $casts = [
        'follow_up_date' => 'date',
        'voided_at' => 'datetime',
        'allergy_conflicts' => 'array',
        'allergy_acknowledged_at' => 'datetime',
    ];

    /** The prescription issued to replace this one after it was cancelled. */
    public function replacedBy(): HasOne
    {
        return $this->hasOne(Prescription::class, 'replaces_prescription_id');
    }

    public function appointment():BelongsTo
    {
        return $this->belongsTo(Appointment::class,'appointment_id');
    }

    public function patient():BelongsTo
    {
        return $this->belongsTo(User::class,'patient_user_id');
    }

    public function doctor():BelongsTo
    {
        return $this->belongsTo(User::class,'doctor_user_id');
    }

    public function items():HasMany
    {
        return $this->hasMany(PrescriptionItem::class,'prescription_id');
    }

    public function tests():HasMany
    {
        return $this->hasMany(PrescriptionTest::class,'prescription_id')->orderBy('order');
    }
}
