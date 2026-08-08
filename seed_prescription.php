$user = \App\Models\User::role('patient')->first();
$doctor = \App\Models\User::role('doctor')->first();

if($user && $doctor) {
    $prescription = \App\Models\Prescription::create([
        'patient_user_id' => $user->id,
        'doctor_user_id' => $doctor->id,
        'diagnosis_summary' => 'Common Cold',
        'status' => 'active'
    ]);
    
    $prescription->items()->create([
        'medicine_name' => 'Paracetamol',
        'dosage' => '500mg',
        'duration_days' => 5
    ]);
    echo "Seeded!";
} else {
    echo "Missing users";
}
