<?php

namespace App\Http\Requests\Api\MedicalRecords;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreMedicalRecordRequest extends FormRequest
{
    public const MAX_PAGES = 10;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            // `files[]` is the multi-page upload; single `file` is kept for older app builds.
            'files'     => ['required_without:file', 'array', 'min:1', 'max:'.self::MAX_PAGES],
            'files.*'   => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'file'      => ['required_without:files', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'notes'     => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The uploaded pages in the order the patient arranged them.
     *
     * @return array<int, UploadedFile>
     */
    public function pages(): array
    {
        return $this->hasFile('files')
            ? array_values($this->file('files'))
            : [$this->file('file')];
    }
}
