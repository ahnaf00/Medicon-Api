<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\ChangePhoneSendRequest;
use App\Http\Requests\Api\Auth\ChangePhoneVerifyRequest;
use App\Http\Resources\UserResource;
use App\Services\PhoneOtpService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Changing the phone number the account logs in with (audit F12). The number
 * is the login identity, so it only changes once a code sent to the new
 * number is entered; PUT /user/me no longer accepts a different number.
 */
class PhoneChangeController extends Controller
{
    public function send(ChangePhoneSendRequest $request, PhoneOtpService $otps): JsonResponse
    {
        if (! $otps->canSend()) {
            return AuthController::otpUnavailable();
        }

        $otps->issue($request->validated('phone'));

        return AuthController::otpSent();
    }

    public function verify(ChangePhoneVerifyRequest $request, PhoneOtpService $otps): JsonResponse
    {
        $phone = $request->validated('phone');

        $result = $otps->check($phone, $request->validated('otp'));
        if ($result !== PhoneOtpService::OK) {
            [$message, $status] = PhoneOtpService::failure($result);

            return response()->json(['message' => $message], $status);
        }

        $user = $request->user();
        try {
            $user->update(['phone' => $phone]);
        } catch (UniqueConstraintViolationException) {
            // Another account took the number between the code and now.
            throw ValidationException::withMessages(['phone' => ['This number is already used by another account.']]);
        }

        return response()->json([
            'message' => 'Phone number changed.',
            'user' => new UserResource($user->fresh(['patientProfile', 'doctorProfile'])),
        ]);
    }
}
