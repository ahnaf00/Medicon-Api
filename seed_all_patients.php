$patients = \App\Models\User::role('patient')->get();
$doctor = \App\Models\User::role('doctor')->first();

if($patients->count() > 0 && $doctor) {
    foreach($patients as $patient) {
        // Avoid duplicate seeding
        $exists = \App\Models\Prescription::where('patient_user_id', $patient->id)->exists();
        if (!$exists) {
            $prescription = \App\Models\Prescription::create([
                'patient_user_id' => $patient->id,
                'doctor_user_id' => $doctor->id,
                'diagnosis_summary' => 'Common Cold',
                'status' => 'active'
            ]);
            
            $prescription->items()->create([
                'medicine_name' => 'Paracetamol',
                'dosage' => '500mg',
                'duration_days' => 5
            ]);
        }
    }
    echo "Seeded for all patients!";
} else {
    echo "Missing users";
}
