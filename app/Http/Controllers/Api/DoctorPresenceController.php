<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Doctors\UpdatePresenceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The doctor's own "Online" toggle. Routes sit behind role:doctor + doctor.verified,
 * and always act on the caller's own profile.
 */
class DoctorPresenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->presence($request->user()->doctorProfile);
    }

    public function update(UpdatePresenceRequest $request): JsonResponse
    {
        $profile = $request->user()->doctorProfile;

        $profile->update([
            'is_online'    => $request->boolean('is_online'),
            'last_seen_at' => now(),
        ]);

        return $this->presence($profile);
    }

    private function presence($profile): JsonResponse
    {
        return response()->json([
            'isOnline'   => (bool) $profile->is_online,
            'lastSeenAt' => $profile->last_seen_at?->toIso8601String(),
        ]);
    }
}
