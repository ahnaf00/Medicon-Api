<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\SymptomTriageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DoctorController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'specialty' => ['nullable', 'string', Rule::in(SymptomTriageService::SPECIALTIES)],
        ]);

        $doctors = User::verifiedDoctors()
            ->with('doctorProfile')
            ->when($request->specialty, fn ($q, $specialty) => $q->whereHas(
                'doctorProfile',
                fn ($p) => $p->where('specialty', $specialty),
            ))
            ->get();

        return UserResource::collection($doctors);
    }

    /**
     * GET /specialties — the departments patients can browse (the same list
     * symptom search recommends from), with each one's count of verified doctors.
     */
    public function specialties(): JsonResponse
    {
        $counts = User::verifiedDoctors()
            ->join('doctor_profiles', 'doctor_profiles.user_id', '=', 'users.id')
            ->selectRaw('doctor_profiles.specialty as specialty, count(*) as total')
            ->groupBy('doctor_profiles.specialty')
            ->pluck('total', 'specialty');

        return response()->json(['data' => collect(SymptomTriageService::SPECIALTIES)
            ->map(fn (string $name) => ['name' => $name, 'doctorCount' => (int) ($counts[$name] ?? 0)])
            ->values()]);
    }

    public function show(int $id):UserResource
    {
        $doctor = User::verifiedDoctors()
                ->with('doctorProfile')
                ->findOrFail($id);

            return new UserResource($doctor);
        }
}
