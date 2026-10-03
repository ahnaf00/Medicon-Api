<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Doctors\UpdatePresenceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The doctor's own "Online" toggle. Routes sit behind role:doctor + doctor.verified,
 * and always act on the caller's own profile. While online, the app calls heartbeat();
 * presence lapses after DoctorProfile::PRESENCE_TTL_MINUTES without one.
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

    /**
     * Keep-alive from the app while the doctor is online. It only refreshes last_seen_at
     * and never switches a doctor on, so a beat that lands after "go offline" is harmless.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $profile = $request->user()->doctorProfile;

        if ($profile->is_online) {
            $profile->update(['last_seen_at' => now()]);
        }

        return $this->presence($profile);
    }

    private function presence($profile): JsonResponse
    {
        return response()->json([
            'isOnline'   => $profile->isOnlineNow(),
            'lastSeenAt' => $profile->last_seen_at?->toIso8601String(),
        ]);
    }
}
