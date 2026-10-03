<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DoctorProfile extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'specialty',
        'qualification',
        'bmdc_registration_no',
        'hospital_name',
        'experience_years',
        'consultation_fee',
        'follow_up_fee',
        'rating',
        'bio',
        'verification_status',
    ];
    protected $casts = [
        'experience_years' => 'integer',
        'consultation_fee' => 'decimal:2',
        'rating' => 'decimal:2',
    ];
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'doctor_user_id', 'user_id');
    }
}
