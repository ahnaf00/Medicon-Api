<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;


class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected $fillable = [
        'name','email','phone','password','status','avatar_url'
    ];

    protected $hidden = [
        'password','remember_token'
    ];


    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function patientProfile():HasOne
    {
        return $this->hasOne(PatientProfile::class, 'user_id');
    }

    public function doctorProfile():HasOne
    {
        return $this->hasOne(DoctorProfile::class,'user_id');
    }

    public function appointmentsAsPatient():HasMany
    {
        return $this->hasMany(Appointment::class,'patient_user_id');
    }

    public function appointmentsAsDoctor():HasMany
    {
        return $this->hasMany(Appointment::class,'doctor_user_id');
    }

    public function PrescriptionsAsPatient():HasMany
    {
        return $this->hasMany(Prescription::class, 'patient_user_id');
    }

    public function PrescriptionsAsDoctor():HasMany
    {
        return $this->hasMany(Prescription::class,'doctor_user_id');
    }

    public function medicalRecords():HasMany
    {
        return $this->hasMany(MedicalRecord::class,'patient_user_id');
    }

    public function billings():HasMany
    {
        return $this->hasMany(Billing::class,'patient_user_id');
    }

    public function vitals():HasMany
    {
        return $this->hasMany(Vital::class, 'user_id');
    }

    public function aiTriageLogs():HasMany
    {
        return $this->hasMany(AiTriageLog::class, 'user_id');
    }
}
