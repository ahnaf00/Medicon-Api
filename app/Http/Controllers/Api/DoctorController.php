<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicDoctorResource;
use App\Models\User;
use App\Services\SymptomTriageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DoctorController extends Controller
{
    /**
     * Verified doctors A-Z, 20 per page (audit DB-003 / H3). `online=1` keeps only
     * doctors online right now, so the app no longer has to load everyone to filter.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'specialty' => ['nullable', 'string', Rule::in(SymptomTriageService::SPECIALTIES)],
            'online' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $doctors = User::verifiedDoctors()
            ->with('doctorProfile')
            ->when($request->specialty, fn ($q, $specialty) => $q->whereHas(
                'doctorProfile',
                fn ($p) => $p->where('specialty', $specialty),
            ))
            ->when($request->boolean('online'), fn ($q) => $q->whereHas(
                'doctorProfile',
                fn ($p) => $p->onlineNow(),
            ))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->integer('per_page', 20));

        return PublicDoctorResource::collection($doctors);
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

    public function show(int $id): PublicDoctorResource
    {
        $doctor = User::verifiedDoctors()
                ->with('doctorProfile')
                ->findOrFail($id);

        return new PublicDoctorResource($doctor);
    }
}
