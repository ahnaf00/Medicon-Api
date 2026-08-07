<?php

use App\Http\Controllers\Api\AiChatController;
use App\Http\Controllers\Api\AiTriageController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DoctorAvailabilityController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\HospitalController;
use App\Http\Controllers\Api\MedicalRecordController;
use App\Http\Controllers\Api\MedicineController;
use App\Http\Controllers\Api\PatientController;
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

    Route::prefix('auth')->middleware('throttle:6,1')->group(function () {
        Route::post('/send-otp', [AuthController::class, 'sendOtp']);
        Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
    });

    // Publicly browse doctors and doctor details
    Route::get('/doctors', [DoctorController::class, 'index']);
    Route::get('/doctors/{id}', [DoctorController::class, 'show']);
    Route::get('/doctors/{id}/slots', [DoctorAvailabilityController::class, 'slots']);

    // Publicly search nearby hospitals & emergency facilities
    Route::get('/hospitals', [HospitalController::class, 'index']);

    // =====================================================================
    // 2. PROTECTED ROUTES (Requires Bearer Token via Sanctum)
    // =====================================================================

    Route::middleware(['auth:sanctum', 'throttle:global'])->group(function () {

        // --- User Profile & Auth ---
        Route::get('/user/me', [AuthController::class, 'me']);
        Route::put('/user/me', [AuthController::class, 'updateProfile']);
        Route::post('/user/avatar', [AuthController::class, 'uploadAvatar']);
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
            Route::get('/patients', [PatientController::class, 'index']);

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

        // --- Conversations / Q&A Domain ---
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::get('/conversations/{id}/messages', [ConversationController::class, 'messages']);
        Route::post('/conversations/{id}/messages', [ConversationController::class, 'sendMessage']);

        // --- AI Chat Domain ---
        Route::middleware('throttle:ai')->group(function () {
            Route::post('/ai/chat', [AiChatController::class, 'chat']);
            Route::get('/ai/sessions', [AiChatController::class, 'sessions']);
            Route::get('/ai/sessions/{id}/messages', [AiChatController::class, 'messages']);
        });

        // --- Medicine / Drug Domain ---
        Route::get('/medicines/search', [MedicineController::class, 'search']);
        Route::post('/medicines/interactions', [MedicineController::class, 'checkInteractions']);
    });

});


