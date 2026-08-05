<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MedicalRecords\StoreMedicalRecordRequest;
use App\Http\Resources\MedicalRecordResource;
use App\Models\MedicalRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
        // Store file to the 'medical-records' disk folder
        $path = $request->file('file')->store('medical-records', 'public');
        $record = MedicalRecord::create([
            'patient_user_id'       => $request->user()->id,
            'recorded_by_user_id'   => $request->user()->id,
            'file_url'              => asset('storage/' . $path),
            'notes'                 => $request->input('notes'),
        ]);

        return response()->json([
            'message' => 'Medical record uploaded successfully.',
            'record'  => new MedicalRecordResource($record),
        ], 201);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $record = MedicalRecord::where('id', $id)
            ->where('patient_user_id', $request->user()->id)
            ->with('recordedBy')
            ->firstOrFail();

        return response()->json(['record' => new MedicalRecordResource($record)]);
    }
    public function destroy(Request $request, $id): JsonResponse
    {
        $record = MedicalRecord::where('id', $id)
            ->where('patient_user_id', $request->user()->id)
            ->firstOrFail();
        // Optionally delete the physical file here:
        // Storage::disk('public')->delete(str_replace(asset('storage/'), '', $record->file_url));
        $record->delete();
        
        return response()->json(['message' => 'Medical record deleted.']);
    }
}
