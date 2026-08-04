<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\PrescriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - MediCon Healthcare Mobile App
|--------------------------------------------------------------------------
*/

// =========================================================================
// 1. PUBLIC ROUTES (Unauthenticated)
// =========================================================================

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Publicly browse doctors and doctor details
Route::get('/doctors', [DoctorController::class, 'index']);
Route::get('/doctors/{id}', [DoctorController::class, 'show']);


// =========================================================================
// 2. PROTECTED ROUTES (Requires Bearer Token via Sanctum)
// =========================================================================

Route::middleware('auth:sanctum')->group(function () {

    // --- User Profile & Auth ---
    Route::get('/user/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // --- Appointments Domain ---
    Route::get('/appointments', [AppointmentController::class, 'index']);
    Route::post('/appointments', [AppointmentController::class, 'store']);
    Route::patch('/appointments/{id}/cancel', [AppointmentController::class, 'cancel']);

    // --- Prescriptions Domain ---
    Route::get('/prescriptions', [PrescriptionController::class, 'index']);
    Route::post('/prescriptions', [PrescriptionController::class, 'store']);

});
