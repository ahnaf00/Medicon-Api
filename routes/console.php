<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

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
