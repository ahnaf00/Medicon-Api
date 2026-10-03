<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\DoctorProfile;
use App\Services\DoctorRankingService;
use App\Services\SymptomTriageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SymptomSearchController extends Controller
{
    public function search(Request $request, SymptomTriageService $triage, DoctorRankingService $ranking): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:500'],
        ]);

        $result = $triage->triage($validated['query']);
        $ranked = $ranking->rank($result['specialty']);

        $doctors = $ranked['doctors']->map(
            fn (DoctorProfile $profile) => $profile->user->setRelation('doctorProfile', $profile)
        );

        return response()->json([
            'specialty' => $result['specialty'],
            'matchedSpecialty' => $ranked['matchedSpecialty'],
            'urgency' => $result['urgency'],
            'redFlag' => $result['redFlag'],
            'source' => $result['source'],
            'doctors' => UserResource::collection($doctors)->resolve($request),
        ]);
    }
}
