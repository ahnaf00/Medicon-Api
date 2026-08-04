<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vital extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'blood_pressure',
        'pulse_rate',
        'glucose_level',
        'oxygen_saturation',
        'logged_at',
    ];
    protected $casts = [
        'pulse_rate'        => 'integer',
        'glucose_level'     => 'decimal:2',
        'oxygen_saturation' => 'integer',
        'logged_at'         => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
