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
        'is_online',
        'last_seen_at',
    ];
    protected $casts = [
        'is_online' => 'boolean',
        'last_seen_at' => 'datetime',
        'experience_years' => 'integer',
        'consultation_fee' => 'decimal:2',
        'rating' => 'decimal:2',
    ];
    /** The app sends a presence heartbeat every 2 minutes while online; this allows for missed beats. */
    public const PRESENCE_TTL_MINUTES = 5;

    /**
     * Online only while the toggle is on and the app has checked in recently, so a doctor
     * who closes the app or loses connection drops offline on their own.
     */
    public function isOnlineNow(): bool
    {
        return $this->is_online
            && $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subMinutes(self::PRESENCE_TTL_MINUTES));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'doctor_user_id', 'user_id');
    }
}
