<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrescriptionUploadPage extends Model
{
    protected $fillable = [
        'prescription_upload_id',
        'file_path',
        'mime_type',
        'page_order',
    ];

    protected $casts = [
        'page_order' => 'integer',
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(PrescriptionUpload::class, 'prescription_upload_id');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }
}
