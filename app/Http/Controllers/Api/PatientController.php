<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
}
