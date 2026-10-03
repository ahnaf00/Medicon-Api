<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MedicalRecords\StoreMedicalRecordRequest;
use App\Http\Resources\MedicalRecordResource;
use App\Jobs\AnalyzeReportJob;
use App\Models\MedicalRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MedicalRecordController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $records = MedicalRecord::where('patient_user_id', $request->user()->id)
            ->with(['recordedBy', 'pages'])
            ->latest()
            ->paginate(15);

        return MedicalRecordResource::collection($records);
    }

    public function store(StoreMedicalRecordRequest $request): JsonResponse
    {
        // Private disk: files are only reachable through signed URLs from the resource.
        $paths = [];
        foreach ($request->pages() as $file) {
            $path = $file->store('medical-records', 'private');
            if ($path === false) {
                Storage::disk('private')->delete($paths);
                abort(500, 'The file could not be stored.');
            }
            $paths[] = $path;
        }

        try {
            $record = DB::transaction(function () use ($request, $paths) {
                $record = MedicalRecord::create([
                    'patient_user_id'       => $request->user()->id,
                    'recorded_by_user_id'   => $request->user()->id,
                    // First page doubles as the legacy single-file pointer.
                    'file_path'             => $paths[0],
                    'notes'                 => $request->input('notes'),
                ]);

                foreach ($paths as $order => $path) {
                    $record->pages()->create(['file_path' => $path, 'page_order' => $order]);
                }

                return $record;
            });
        } catch (Throwable $e) {
            Storage::disk('private')->delete($paths);
            throw $e;
        }

        return response()->json([
            'message' => 'Medical record uploaded successfully.',
            'record'  => new MedicalRecordResource($record->fresh(['pages'])),
        ], 201);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $record = MedicalRecord::with(['recordedBy', 'pages', 'labResults'])->findOrFail($id);

        Gate::authorize('view', $record);

        return response()->json(['record' => new MedicalRecordResource($record)]);
    }

    public function analyze(Request $request, $id): JsonResponse
    {
        $record = MedicalRecord::findOrFail($id);

        Gate::authorize('analyze', $record);

        // Don't queue a second run while one is in flight, unless it has been stuck for a while.
        $inFlight = $record->analysis_status === 'processing'
            && $record->updated_at?->gt(now()->subMinutes(5));

        if (! $inFlight) {
            $record->forceFill([
                'analysis_status' => 'processing',
                'analysis_error'  => null,
                'updated_at'      => now(),
            ])->save();

            AnalyzeReportJob::dispatch($record);
        }

        return response()->json([
            'message' => 'Report analysis started.',
            'record'  => new MedicalRecordResource($record->fresh(['pages', 'labResults'])),
        ], 202);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $record = MedicalRecord::findOrFail($id);

        Gate::authorize('delete', $record);

        $paths = $record->pages()->pluck('file_path')
            ->push($record->file_path)
            ->filter()
            ->unique()
            ->all();
        Storage::disk('private')->delete($paths);
        $record->delete();

        return response()->json(['message' => 'Medical record deleted.']);
    }
}
