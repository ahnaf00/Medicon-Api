<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_user_id',
        'doctor_user_id',
        'appointment_datetime',
        'duration_minutes',
        'format',
        'status',
        'notes',
    ];

     protected $casts = [
        'appointment_datetime' => 'datetime',
    ];

    public function patient():BelongsTo
    {
        return $this->belongsTo(User::class,'patient_user_id');
    }

    public function doctor():BelongsTo
    {
        return $this->belongsTo(User::class,'doctor_user_id');
    }

    public function prescription():HasOne
    {
        return $this->hasOne(Prescription::class,'appointment_id');
    }

    public function billing():HasOne
    {
        return $this->hasOne(Billing::class,'appointment_id');
    }
}
