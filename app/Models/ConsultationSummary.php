<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The structured record of what happened in a consultation. Written by the
 * doctor today (`source = doctor_note`); a transcript pipeline can fill the
 * same fields later without changing anything downstream.
 */
class ConsultationSummary extends Model
{
    protected $fillable = [
        'appointment_id',
        'doctor_user_id',
        'patient_user_id',
        'chief_complaint',
        'findings',
        'advice',
        'red_flags',
        'source',
    ];

    protected $casts = [
        'red_flags' => 'array',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_user_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_user_id');
    }
}
