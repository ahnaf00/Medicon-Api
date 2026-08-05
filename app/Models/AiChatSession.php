<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiChatSession extends Model
{
    protected $fillable = ['user_id', 'title'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    public function messages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class);
    }
    public function latestMessage(): HasOne
    {
        return $this->hasOne(AiChatMessage::class)->latestOfMany();
    }
}
