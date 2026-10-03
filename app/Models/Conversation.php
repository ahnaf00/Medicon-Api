<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    protected $fillable = ['patient_user_id', 'doctor_user_id', 'subject', 'status', 'department', 'is_anonymous'];
    protected $casts = ['is_anonymous' => 'boolean'];

    /** True when the patient asked anonymously and the viewer is not that patient. */
    public function hidesPatientFrom(?User $viewer): bool
    {
        return $this->is_anonymous && $viewer?->id !== (int) $this->patient_user_id;
    }

    /** Whether anyone other than the asking patient has posted in the thread. */
    public function hasReply(): bool
    {
        return $this->messages()->where('sender_user_id', '!=', $this->patient_user_id)->exists();
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_user_id');
    }
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_user_id');
    }
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function firstMessage(): HasOne
    {
        return $this->hasOne(Message::class)->oldestOfMany();
    }
}
