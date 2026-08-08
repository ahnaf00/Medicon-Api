<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class MedicalRecordSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $patients = \App\Models\User::role('patient')->get();
        $doctor = \App\Models\User::role('doctor')->first();

        if ($patients->isEmpty()) {
            $this->command->info('No patients found. Please create patients first.');
            return;
        }

        foreach ($patients as $patient) {
            \App\Models\MedicalRecord::factory(3)->create([
                'patient_user_id' => $patient->id,
                'recorded_by_user_id' => $doctor ? $doctor->id : $patient->id,
            ]);
        }

        $this->command->info('Medical records seeded successfully.');
    }
}
