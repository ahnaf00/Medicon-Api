<?php

use App\Http\Controllers\Api\AiTriageController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\HospitalController;
use App\Http\Controllers\Api\MedicalRecordController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\VitalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - MediCon Healthcare Mobile App
|--------------------------------------------------------------------------
*/

// =========================================================================
// All API routes are versioned under /api/v1
// =========================================================================

Route::prefix('v1')->group(function () {

    // =====================================================================
    // 1. PUBLIC ROUTES (Unauthenticated)
    // =====================================================================

    Route::prefix('auth')->middleware('throttle:auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
    });

    // Publicly browse doctors and doctor details
    Route::get('/doctors', [DoctorController::class, 'index']);
    Route::get('/doctors/{id}', [DoctorController::class, 'show']);

    // Publicly search nearby hospitals & emergency facilities
    Route::get('/hospitals', [HospitalController::class, 'index']);

    // =====================================================================
    // 2. PROTECTED ROUTES (Requires Bearer Token via Sanctum)
    // =====================================================================

    Route::middleware(['auth:sanctum', 'throttle:global'])->group(function () {

        // --- User Profile & Auth ---
        Route::get('/user/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // --- Appointments Domain ---
        Route::get('/appointments', [AppointmentController::class, 'index']);
        Route::patch('/appointments/{id}/cancel', [AppointmentController::class, 'cancel']);

        // --- Patient-Restricted Routes ---
        Route::middleware('role:patient')->group(function () {
            Route::post('/appointments', [AppointmentController::class, 'store']);
        });

        // --- Doctor-Restricted Routes ---
        Route::middleware('role:doctor')->group(function () {
            Route::post('/prescriptions', [PrescriptionController::class, 'store']);
        });

        Route::get('/prescriptions', [PrescriptionController::class, 'index']);

        // --- Vitals Tracking Domain ---
        Route::get('/vitals', [VitalController::class, 'index']);
        Route::post('/vitals', [VitalController::class, 'store']);

        // --- AI Symptom Triage (stricter rate limit) ---
        Route::post('/ai/triage', [AiTriageController::class, 'store'])
            ->middleware('throttle:ai');

        // --- Medical Records Domain ---
        Route::apiResource('medical-records', MedicalRecordController::class)->except(['update']);

    });

}); // end v1
