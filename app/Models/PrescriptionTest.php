<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrescriptionTest extends Model
{
    protected $fillable = [
        'prescription_id',
        'name',
        'instructions',
        'order',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class, 'prescription_id');
    }
}
