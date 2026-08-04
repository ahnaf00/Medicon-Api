<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prescription extends Model
{
    use HasFactory;

    protected $fillable = [
        'appointment_id',
        'patient_user_id',
        'doctor_user_id',
        'diagnosis_summary',
        'status',
    ];

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
}
