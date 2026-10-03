<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PatientController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $doctorId = $request->user()->id;

        // Get the IDs of all distinct patients this doctor has seen
        $patientIds = Appointment::where('doctor_user_id', $doctorId)
            ->distinct()
            ->pluck('patient_user_id');

        $patients = User::whereIn('id', $patientIds)
            ->with('patientProfile')
            ->orderBy('name')
            ->paginate(20);

        return UserResource::collection($patients);
    }

    public function show(Request $request, $id)
    {
        $patient = User::where('id', $id)->with('patientProfile')->firstOrFail();

        abort_if($patient->patientProfile === null, 404);

        // The doctor must have an appointment with this patient.
        Gate::authorize('view', $patient->patientProfile);

        return new UserResource($patient);
    }
}
