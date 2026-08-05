<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\DoctorProfile;
use App\Models\Hospital;
use App\Models\PatientProfile;
use App\Models\Prescription;
use App\Models\User;
use App\Models\Vital;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // 1. Seed Spatie Roles
        $this->call(RolesAndPermissionsSeeder::class);
        // 2. Create Doctors
        $doctor1 = User::factory()->create([
            'name' => 'Dr. Sarah Khan',
            'email' => 'doctor@medicon.com',
            'phone' => '+8801711000001',
        ]);
        $doctor1->assignRole('doctor');
        DoctorProfile::factory()->create([
            'user_id' => $doctor1->id,
            'specialty' => 'General Medicine',
            'consultation_fee' => 700.00,
        ]);
        $doctor2 = User::factory()->create([
            'name' => 'Dr. Ahmed Rahman',
            'email' => 'ahmed.rahman@medicon.com',
            'phone' => '+8801711000002',
        ]);
        $doctor2->assignRole('doctor');
        DoctorProfile::factory()->create([
            'user_id' => $doctor2->id,
            'specialty' => 'Cardiology',
            'consultation_fee' => 1200.00,
        ]);
        // 3. Create Patient
        $patient = User::factory()->create([
            'name' => 'Rahat Ahmed',
            'email' => 'patient@medicon.com',
            'phone' => '+8801811000001',
        ]);
        $patient->assignRole('patient');
        PatientProfile::factory()->create(['user_id' => $patient->id]);
        // 4. Create Appointments
        $appointment = Appointment::factory()->create([
            'patient_user_id' => $patient->id,
            'doctor_user_id' => $doctor1->id,
            'format' => 'video',
            'status' => 'scheduled',
        ]);
        // 5. Create Prescriptions & Items
        $prescription = Prescription::factory()->create([
            'appointment_id' => $appointment->id,
            'patient_user_id' => $patient->id,
            'doctor_user_id' => $doctor1->id,
            'diagnosis_summary' => 'Seasonal Flu & Mild Fever',
        ]);
        $prescription->items()->createMany([
            [
                'medicine_name' => 'Paracetamol 500mg',
                'dosage' => '1 tablet',
                'dosage_schedule' => ['morning' => '08:00', 'noon' => '14:00', 'night' => '20:00'],
                'instructions' => 'Take after meal',
                'duration_days' => 5,
            ],
            [
                'medicine_name' => 'Histacin 4mg',
                'dosage' => '1 tablet',
                'dosage_schedule' => ['night' => '21:00'],
                'instructions' => 'Take before sleep',
                'duration_days' => 3,
            ]
        ]);
        // 6. Create Vitals Logs for Patient
        Vital::factory()->count(5)->create(['user_id' => $patient->id]);
        // 7. Seed Nearby Hospitals
        Hospital::factory()->count(4)->create();
    }
}
