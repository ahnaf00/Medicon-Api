<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Vitals\StoreVitalRequest;
use App\Http\Resources\VitalResource;
use App\Models\Vital;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VitalController extends Controller
{
    /** The caller's readings, newest first, 20 per page (audit DB-003 / H3). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $vitals = Vital::where('user_id', $request->user()->id)
            ->orderBy('logged_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($request->integer('per_page', 20));
        return VitalResource::collection($vitals);
    }

    public function store(StoreVitalRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $vital = Vital::create([
            'user_id'           => $request->user()->id,
            'blood_pressure'    => $validated['blood_pressure'] ?? null,
            'pulse_rate'        => $validated['pulse_rate'] ?? null,
            'glucose_level'     => $validated['glucose_level'] ?? null,
            'oxygen_saturation' => $validated['oxygen_saturation'] ?? null,
            'logged_at'         => $validated['logged_at'] ?? now(),
        ]);
        return response()->json([
            'message'   => 'Vital metrics recorded successfully.',
            'vital'     => new VitalResource($vital),
        ], 201);
    }

}
