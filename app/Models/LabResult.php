<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabResult extends Model
{
    public const STATUSES = ['low', 'normal', 'high', 'unknown'];

    protected $fillable = [
        'medical_record_id',
        'panel',
        'sub_group',
        'name',
        'value',
        'unit',
        'reference_text',
        'reference_low',
        'reference_high',
        'status',
        'order',
    ];

    protected $casts = [
        'reference_low' => 'float',
        'reference_high' => 'float',
        'order' => 'integer',
    ];

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'medical_record_id');
    }
}
