<?php

use App\Http\Controllers\Api\AiChatController;
use App\Http\Controllers\Api\AiTriageController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConsultationCallController;
use App\Http\Controllers\Api\ConsultationChatController;
use App\Http\Controllers\Api\ConsultationController;
use App\Http\Controllers\Api\ConsultationTranscriptController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DoctorAvailabilityController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\HospitalController;
use App\Http\Controllers\Api\Internal\TranscriberController;
use App\Http\Controllers\Api\MedicalRecordController;
use App\Http\Controllers\Api\MedicineController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\SymptomSearchController;
use App\Http\Controllers\Api\VitalController;
use App\Http\Controllers\Api\DoctorDashboardController;
use App\Http\Controllers\Api\DoctorPresenceController;
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

    // Transcriber agent (tools/transcriber): not a user, authenticated by the
    // shared X-Transcriber-Secret instead of Sanctum.
    Route::prefix('internal/transcriber')->middleware(['transcriber', 'throttle:120,1'])->group(function () {
        Route::post('/rooms/{room}/chunks', [TranscriberController::class, 'chunks']);
        Route::post('/rooms/{room}/complete', [TranscriberController::class, 'complete']);
    });

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
        Route::get('/consultations/{appointmentId}/summary', [ConsultationController::class, 'show']);
        Route::get('/consultations/{appointmentId}/transcript', [ConsultationTranscriptController::class, 'show']);

        // --- Video consultation (both participants; AppointmentPolicy::joinCall) ---
        Route::post('/appointments/{id}/call/consent', [ConsultationCallController::class, 'consent']);
        Route::post('/appointments/{id}/call/token', [ConsultationCallController::class, 'token']);

        // --- Patient-Restricted Routes ---
        Route::middleware('role:patient')->group(function () {
            Route::post('/appointments', [AppointmentController::class, 'store']);
        });

        // --- Doctor-Restricted Routes ---
        Route::middleware(['role:doctor', 'doctor.verified'])->group(function () {
            Route::get('/doctor/dashboard', [DoctorDashboardController::class, 'index']);
            Route::get('/doctor/presence', [DoctorPresenceController::class, 'show']);
            Route::post('/doctor/presence', [DoctorPresenceController::class, 'update']);
            Route::post('/doctor/presence/heartbeat', [DoctorPresenceController::class, 'heartbeat']);
            Route::patch('/appointments/{id}/status', [AppointmentController::class, 'updateStatus']);
            Route::post('/appointments/{id}/call/end', [ConsultationCallController::class, 'end']);
            Route::put('/consultations/{appointmentId}/summary', [ConsultationController::class, 'upsert']);
            Route::post('/consultations/{appointmentId}/transcript/retry', [ConsultationTranscriptController::class, 'retry'])
                ->middleware('throttle:ai');
            Route::post('/prescriptions', [PrescriptionController::class, 'store']);
            Route::get('/patients', [PatientController::class, 'index']);
            Route::get('/patients/{id}', [PatientController::class, 'show']);

            Route::get('/doctor/availability', [DoctorAvailabilityController::class, 'mySlots']);
            Route::put('/doctor/availability', [DoctorAvailabilityController::class, 'updateSlots']);
            Route::get('/doctor/exceptions', [DoctorAvailabilityController::class, 'getExceptions']);
            Route::post('/doctor/exceptions/toggle', [DoctorAvailabilityController::class, 'toggleException']);

        });

        Route::get('/prescriptions', [PrescriptionController::class, 'index']);
        Route::get('/prescriptions/{id}', [PrescriptionController::class, 'show']);
        Route::get('/prescriptions/{id}/document', [PrescriptionController::class, 'document']);
        Route::get('/prescriptions/{id}/download', [PrescriptionController::class, 'download']);

        // --- Vitals Tracking Domain ---
        Route::get('/vitals', [VitalController::class, 'index']);
        Route::post('/vitals', [VitalController::class, 'store']);

        // --- AI Symptom Triage (stricter rate limit) ---
        Route::post('/ai/triage', [AiTriageController::class, 'store'])
            ->middleware('throttle:ai');

        // --- Symptom search: triage + ranked doctors ---
        Route::post('/symptom-search', [SymptomSearchController::class, 'search'])
            ->middleware('throttle:ai');

        // --- Medical Records Domain ---
        Route::apiResource('medical-records', MedicalRecordController::class)->except(['update']);
        Route::post('/medical-records/{id}/analyze', [MedicalRecordController::class, 'analyze'])
            ->middleware('throttle:ai');

        // --- Conversations / Q&A Domain ---
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::patch('/conversations/{id}', [ConversationController::class, 'update']);
        Route::delete('/conversations/{id}', [ConversationController::class, 'destroy']);
        Route::get('/conversations/{id}/messages', [ConversationController::class, 'messages']);
        Route::post('/conversations/{id}/messages', [ConversationController::class, 'sendMessage']);
        Route::patch('/conversations/{id}/messages/{messageId}', [ConversationController::class, 'updateMessage']);

        // --- AI Chat Domain ---
        Route::middleware('throttle:ai')->group(function () {
            Route::post('/ai/chat', [AiChatController::class, 'chat']);
            Route::get('/ai/sessions', [AiChatController::class, 'sessions']);
            Route::get('/ai/sessions/{id}/messages', [AiChatController::class, 'messages']);
            Route::post('/ai/consultation-chat', [ConsultationChatController::class, 'chat']);
        });

        // --- Medicine / Drug Domain ---
        Route::get('/medicines/search', [MedicineController::class, 'search']);
        Route::post('/medicines/interactions', [MedicineController::class, 'checkInteractions']);
    });

});


