<?php

use App\Models\Appointment;
use App\Models\Prescription;
use App\Models\User;
use App\Services\DoctorSlotService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// There is no admin UI yet; this is how a self-registered (pending) doctor is approved.
Artisan::command('doctor:verify {doctor : User id or exact phone number}', function (string $doctor) {
    $user = User::role('doctor')
        ->with('doctorProfile')
        ->where(fn ($q) => $q->where('phone', $doctor)->when(ctype_digit($doctor), fn ($q) => $q->orWhere('id', (int) $doctor)))
        ->first();

    if (! $user || ! $user->doctorProfile) {
        $this->error("No doctor with a profile matches [{$doctor}].");

        return 1;
    }

    $user->doctorProfile->update(['verification_status' => 'verified']);
    $this->info("Verified Dr. #{$user->id} {$user->name} ({$user->phone}).");

    return 0;
})->purpose('Mark a doctor as verified so they can use doctor features');

// Local testing: a video consultation that is ready to start right now, between the
// seeded test accounts (or any doctor/patient given). Run it again for a fresh one.
Artisan::command('demo:video-call
    {--doctor=doctor@medicon.com : Doctor email, phone or id}
    {--patient=patient@medicon.com : Patient email, phone or id}', function () {
    if (app()->environment('production')) {
        $this->error('demo:video-call creates test data and is disabled in production.');

        return 1;
    }

    $find = fn (string $role, string $key) => User::role($role)
        ->where(fn ($q) => $q->where('email', $key)->orWhere('phone', $key)
            ->when(ctype_digit($key), fn ($q) => $q->orWhere('id', (int) $key)))
        ->first();

    $doctor = $find('doctor', (string) $this->option('doctor'));
    $patient = $find('patient', (string) $this->option('patient'));

    if (! $doctor || ! $patient) {
        $this->error('Doctor or patient not found. Run the seeder, or pass --doctor / --patient.');

        return 1;
    }
    if (! $doctor->isVerifiedDoctor()) {
        $this->error("Dr. #{$doctor->id} is not verified; run: php artisan doctor:verify {$doctor->id}");

        return 1;
    }

    $appointment = Appointment::create([
        'patient_user_id' => $patient->id,
        'doctor_user_id' => $doctor->id,
        'appointment_datetime' => now()->startOfMinute(),
        'format' => 'video',
        'status' => 'scheduled',
        'notes' => 'Demo video consultation (demo:video-call)',
    ]);

    $this->info("Appointment #{$appointment->id}: {$doctor->name} with {$patient->name}, video, starting now.");
    $this->line('Doctor: Dashboard > Today\'s appointments > tap it > Start video call.');
    $this->line('Patient: Home > Next appointment > Join video call (appears once the doctor starts).');

    return 0;
})->purpose('Create a video appointment between test accounts that is ready to start now');

// A course ends on its issue day + the longest medicine duration - 1 (Dhaka dates,
// as the app shows them). Ended prescriptions stop being "active" (audit P10).
Artisan::command('prescriptions:complete-ended', function () {
    $tz = DoctorSlotService::timezone();
    $today = now($tz)->startOfDay();
    $done = 0;

    Prescription::where('status', 'active')
        ->withMax('items', 'duration_days')
        ->chunkById(200, function ($prescriptions) use ($tz, $today, &$done) {
            foreach ($prescriptions as $prescription) {
                $lastDay = $prescription->created_at->copy()->setTimezone($tz)->startOfDay()
                    ->addDays(max(1, (int) $prescription->items_max_duration_days) - 1);

                if ($lastDay->lt($today)) {
                    $prescription->update(['status' => 'completed']);
                    $done++;
                }
            }
        });

    $this->info("Marked {$done} ended prescription(s) completed.");

    return 0;
})->purpose('Mark active prescriptions whose course has ended as completed');

Schedule::command('prescriptions:complete-ended')->dailyAt('00:10')->timezone(DoctorSlotService::timezone());
