<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiTriageLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'symptoms_summary',
        'urgency_level',
        'recommended_action',
    ];
    /**
     * Get the user that executed the AI triage session.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
