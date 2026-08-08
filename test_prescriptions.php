$user = \App\Models\User::role('patient')->first();

$prescriptions = \App\Models\Prescription::with(['items', 'doctor.doctorProfile', 'patient.patientProfile'])
    ->where('patient_user_id', $user->id)
    ->get();
    
echo json_encode(\App\Http\Resources\PrescriptionResource::collection($prescriptions)->resolve());
