<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DoctorScheduleException extends Model
{
    use HasFactory;

    protected $fillable = [
        'doctor_user_id',
        'date',
        'time',
        'type',
    ];

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_user_id');
    }
}
