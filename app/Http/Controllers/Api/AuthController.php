<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Requests\Api\Auth\RegisterRequest;
use App\Http\Requests\Api\Auth\UpdateProfileRequest;
use App\Http\Requests\Api\Auth\SendOtpRequest;
use App\Http\Requests\Api\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\DoctorProfile;
use App\Models\PatientProfile;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\PhoneOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public static function otpUnavailable(): JsonResponse
    {
        return response()->json(['message' => 'OTP delivery is not configured on this server.'], 503);
    }

    public static function otpSent(): JsonResponse
    {
        return response()->json([
            'message' => 'Demo mode: no SMS is sent. Use the demo code '.PhoneOtpService::DEMO_OTP.'.',
            'demo' => true,
        ], 200);
    }

    private static function registrationProofKey(string $phone): string
    {
        return 'registration_proof:'.$phone;
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Registration is only allowed with the single-use proof verifyOtp()
        // issued for this same phone, so nobody can claim someone else's number.
        $proofKey = self::registrationProofKey($validated['phone']);
        $expected = Cache::get($proofKey);

        if (! $expected || ! hash_equals($expected, hash('sha256', $validated['registration_token']))) {
            throw ValidationException::withMessages([
                'registration_token' => ['Phone verification is missing or has expired. Verify your phone again.'],
            ]);
        }

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'password' => isset($validated['password']) ? Hash::make($validated['password']) : null,
                'status' => $validated['role'] === 'doctor' ? 'pending' : 'active',
            ]);

            $user->assignRole($validated['role']);

            if ($validated['role'] === 'doctor') {
                DoctorProfile::create([
                    'user_id' => $user->id,
                    'specialty' => $validated['specialty'],
                    'qualification' => $validated['qualification'],
                    'bmdc_registration_no' => $validated['bmdc_registration_no'] ?? null,
                    'hospital_name' => $validated['hospital_name'] ?? null,
                    'consultation_fee' => $validated['consultation_fee'] ?? 0.00,
                    'verification_status' => 'pending',
                ]);
            } else {
                PatientProfile::create([
                    'user_id' => $user->id,
                    'date_of_birth' => $validated['date_of_birth'] ?? null,
                    'gender' => $validated['gender'] ?? null,
                    'blood_group' => $validated['blood_group'] ?? null,
                    'height_cm' => $validated['height_cm'] ?? null,
                    'weight_kg' => $validated['weight_kg'] ?? null,
                    'allergies' => $validated['allergies'] ?? null,
                    'chronic_conditions' => $validated['chronic_conditions'] ?? null,
                ]);
            }

            return $user;
        });

        Cache::forget($proofKey);

        $token = $user->createToken('auth_token')->plainTextToken;
        return response()->json([
            'message' => 'User registered successfully.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->getRoleNames()->first(),
                'status' => $user->status,
            ]
        ], 201);
    }

    public function sendOtp(SendOtpRequest $request, PhoneOtpService $otps): JsonResponse
    {
        // No SMS provider is integrated, so a real code could never reach the
        // user. The only working mode is the explicit demo mode with a fixed code.
        if (! $otps->canSend()) {
            return self::otpUnavailable();
        }

        $otps->issue($request->validated('phone'));

        return self::otpSent();
    }

    public function verifyOtp(VerifyOtpRequest $request, PhoneOtpService $otps): JsonResponse
    {
        $validated = $request->validated();
        $phone = $validated['phone'];

        $result = $otps->check($phone, $validated['otp']);
        if ($result !== PhoneOtpService::OK) {
            [$message, $status] = PhoneOtpService::failure($result);

            return response()->json(['message' => $message], $status);
        }

        $user = User::withTrashed()->where('phone', $phone)->first();

        // A deleted account in its grace period may be restored, never silently re-registered (audit L3).
        if ($user?->trashed()) {
            return response()->json([
                'isNewUser' => false,
                'accountDeleted' => true,
                'purgeAt' => app(AccountDeletionService::class)->purgeDate($user)?->toIso8601String(),
                'restore_token' => AccountController::issueRestoreToken($user),
            ], 200);
        }

        if (!$user) {
            $proof = Str::random(64);
            Cache::put(self::registrationProofKey($phone), hash('sha256', $proof), now()->addMinutes(15));

            return response()->json(['isNewUser' => true, 'registration_token' => $proof], 200);
        }

        if ($user->status === 'suspended') {
            return response()->json(['message' => 'Account is suspended'], 403);
        }

        return response()->json(self::signedInPayload($user), 200);
    }

    /** What verify-otp (and an account restore) returns: a new token and who signed in. */
    public static function signedInPayload(User $user): array
    {
        return [
            'isNewUser' => false,
            'access_token' => $user->createToken('auth_token')->plainTextToken,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'role' => $user->getRoleNames()->first(),
                'status' => $user->status,
            ],
        ];
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::where('email', $validated['login'])
            ->orWhere('phone', $validated['login'])
            ->first();


        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status === 'suspended') {
            return response()->json(['message' => 'Account is suspended'], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;
        return response()->json([
            'message' => 'Login successful.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->getRoleNames()->first(),
                'status' => $user->status,
            ]
        ], 200);
    }

    public function me(Request $request): UserResource
    {
        // Same shape as updateProfile: the client reads qualifications etc. from these.
        $user = $request->user()->loadMissing(['patientProfile', 'doctorProfile']);

        return new UserResource($user);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ], 200);
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        // Update core user fields
        $user->fill(array_intersect_key($validated, array_flip(['name', 'phone'])));
        $user->save();

        // Update role-specific profile
        if ($user->hasRole('patient')) {
            $profileFields = array_intersect_key($validated, array_flip([
                'date_of_birth',
                'gender',
                'blood_group',
                'emergency_contact',
                'address',
            ]));
            $user->patientProfile()->updateOrCreate(['user_id' => $user->id], $profileFields);
        }

        if ($user->hasRole('doctor')) {
            $profileFields = array_intersect_key($validated, array_flip([
                'specialty',
                'qualification',
                'experience_years',
                'consultation_fee',
                'bio',
                'bmdc_registration_no',
                'hospital_name',
            ]));
            $user->doctorProfile()->updateOrCreate(['user_id' => $user->id], $profileFields);
        }

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => new UserResource($user->fresh(['patientProfile', 'doctorProfile'])),
        ]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $user = $request->user();

        // Delete old avatar if it exists
        if ($user->avatar_url) {
            $oldPath = str_replace(asset('storage/'), '', $user->avatar_url);
            Storage::disk('public')->delete($oldPath);
        }

        $path = $request->file('avatar')->store('avatars', 'public');

        $user->update(['avatar_url' => asset('storage/' . $path)]);

        return response()->json([
            'message' => 'Avatar uploaded successfully.',
            'avatarUrl' => $user->avatar_url,
        ]);
    }
}
