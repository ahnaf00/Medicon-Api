<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Billing extends Model
{
    use HasFactory;
    protected $fillable = [
        'appointment_id',
        'patient_user_id',
        'amount',
        'payment_status',
        'payment_method',
        'transaction_id',
    ];
    protected $casts = [
        'amount' => 'decimal:2',
    ];
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }
    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_user_id');
    }
}
