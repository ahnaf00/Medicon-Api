<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MedicalRecords\StoreMedicalRecordRequest;
use App\Http\Resources\MedicalRecordResource;
use App\Models\MedicalRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class MedicalRecordController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $records = MedicalRecord::where('patient_user_id', $request->user()->id)
            ->with('recordedBy')
            ->latest()
            ->paginate(15);

        return MedicalRecordResource::collection($records);
    }

    public function store(StoreMedicalRecordRequest $request): JsonResponse
    {
        // Private disk: the file is only reachable through a signed URL from the resource.
        $path = $request->file('file')->store('medical-records', 'private');
        abort_if($path === false, 500, 'The file could not be stored.');

        $record = MedicalRecord::create([
            'patient_user_id'       => $request->user()->id,
            'recorded_by_user_id'   => $request->user()->id,
            'file_path'             => $path,
            'notes'                 => $request->input('notes'),
        ]);

        return response()->json([
            'message' => 'Medical record uploaded successfully.',
            'record'  => new MedicalRecordResource($record),
        ], 201);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $record = MedicalRecord::with('recordedBy')->findOrFail($id);

        Gate::authorize('view', $record);

        return response()->json(['record' => new MedicalRecordResource($record)]);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $record = MedicalRecord::findOrFail($id);

        Gate::authorize('delete', $record);

        if ($record->file_path) {
            Storage::disk('private')->delete($record->file_path);
        }
        $record->delete();

        return response()->json(['message' => 'Medical record deleted.']);
    }
}
