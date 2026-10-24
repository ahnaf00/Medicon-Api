<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Prescriptions\StorePrescriptionUploadRequest;
use App\Http\Resources\PrescriptionUploadResource;
use App\Models\PrescriptionUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Paper prescriptions a patient uploads (task 1.7). Stored as the original
 * pages only: no OCR or medicine extraction happens here.
 */
class PrescriptionUploadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $uploads = PrescriptionUpload::with('pages')
            ->where('patient_user_id', $request->user()->id)
            ->latest()
            ->latest('id')
            ->get();

        return response()->json(['uploads' => PrescriptionUploadResource::collection($uploads)]);
    }

    public function store(StorePrescriptionUploadRequest $request): JsonResponse
    {
        $userId = $request->user()->id;

        // Write the files first; if the rows can't be saved, remove them again.
        $pages = [];
        foreach (array_values($request->file('files')) as $file) {
            $path = $file->store("prescription-uploads/{$userId}", 'private');
            if ($path === false) {
                Storage::disk('private')->delete(array_column($pages, 'file_path'));
                abort(500, 'The file could not be stored.');
            }
            $pages[] = [
                'file_path' => $path,
                'mime_type' => strtolower($file->getClientOriginalExtension()) === 'pdf'
                    ? 'application/pdf'
                    : ($file->getMimeType() ?: 'image/jpeg'),
            ];
        }

        try {
            $upload = DB::transaction(function () use ($request, $userId, $pages) {
                $upload = PrescriptionUpload::create([
                    'patient_user_id' => $userId,
                    'title'           => $request->input('title'),
                    'doctor_name'     => $request->input('doctor_name'),
                    'issued_on'       => $request->input('issued_on'),
                    'notes'           => $request->input('notes'),
                ]);

                foreach ($pages as $order => $page) {
                    $upload->pages()->create($page + ['page_order' => $order]);
                }

                return $upload;
            });
        } catch (Throwable $e) {
            Storage::disk('private')->delete(array_column($pages, 'file_path'));
            throw $e;
        }

        return response()->json([
            'message' => 'Prescription uploaded.',
            'upload'  => new PrescriptionUploadResource($upload->load('pages')),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $upload = PrescriptionUpload::with('pages')->findOrFail($id);

        Gate::authorize('view', $upload);

        return response()->json(['upload' => new PrescriptionUploadResource($upload)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $upload = PrescriptionUpload::with('pages')->findOrFail($id);

        Gate::authorize('delete', $upload);

        $paths = $upload->pages->pluck('file_path')->all();
        $upload->delete();
        Storage::disk('private')->delete($paths);

        return response()->json(['message' => 'Uploaded prescription deleted.']);
    }
}
