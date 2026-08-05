<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Requests\Api\Auth\RegisterRequest;
use App\Http\Requests\Api\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\DoctorProfile;
use App\Models\PatientProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
                'status' => $validated['role'] === 'doctor' ? 'pending' : 'active',
            ]);

            $user->assignRole($validated['role']);

            if ($validated['role'] === 'doctor') {
                DoctorProfile::create([
                    'user_id' => $user->id,
                    'specialty' => $validated['specialty'],
                    'qualification' => $validated['qualification'],
                    'consultation_fee' => $validated['consultation_fee'] ?? 0.00,
                    'verification_status' => 'pending',
                ]);
            } else {
                PatientProfile::create([
                    'user_id' => $user->id,
                    'date_of_birth' => $validated['date_of_birth'] ?? null,
                    'gender' => $validated['gender'] ?? null,
                    'blood_group' => $validated['blood_group'] ?? null,
                ]);
            }

            return $user;
        });

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
            return response()->json([
                'message' => 'Account is suspended',
                403
            ]);
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
        $user = $request->user();

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
