<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A paper prescription the patient uploaded; stored as the original pages only. */
class PrescriptionUpload extends Model
{
    protected $fillable = [
        'patient_user_id',
        'title',
        'doctor_name',
        'issued_on',
        'notes',
    ];

    protected $casts = [
        'issued_on' => 'date',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_user_id');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(PrescriptionUploadPage::class)->orderBy('page_order');
    }
}
