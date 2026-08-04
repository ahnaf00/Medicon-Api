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
    public function index(Request $request): AnonymousResourceCollection
    {
        $vitals = Vital::where('user_id', $request->user()->id)
            ->orderBy('logged_at', 'desc')
            ->get();
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
