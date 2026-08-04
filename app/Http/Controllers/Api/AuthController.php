<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Requests\Api\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\DoctorProfile;
use App\Models\PatientProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\UseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request):JsonResponse
    {
        $validated = $request->validated();

        $user = DB::transaction(function() use ($validated) {
            $user = User::create([
                'name'      => $validated['name'],
                'email'     => $validated['email'],
                'phone'     => $validated['phone'] ?? null,
                'password'  => Hash::make($validated['password']),
                'status'    => $validated['role'] === 'doctor' ? 'pending' : 'active',
            ]);

            $user->assignRole($validated['role']);

            if ($validated['role'] === 'doctor') {
                DoctorProfile::create([
                    'user_id'               => $user->id,
                    'specialty'             => $validated['specialty'],
                    'qualification'         => $validated['qualification'],
                    'consultation_fee'      => $validated['consultation_fee'] ?? 0.00,
                    'verification_status'   => 'pending',
                ]);
            } else {
                PatientProfile::create([
                    'user_id'       => $user->id,
                    'date_of_birth' => $validated['date_of_birth'] ?? null,
                    'gender'        => $validated['gender'] ?? null,
                    'blood_group'   => $validated['blood_group'] ?? null,
                ]);
            }

            return $user;
        });

        $token = $user->createToken('auth_token')->plainTextToken;
        return response()->json([
            'message'       => 'User registered successfully.',
            'access_token'  => $token,
            'token_type'    => 'Bearer',
            'user' => [
                'id'        => $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'phone'     => $user->phone,
                'role'      => $user->getRoleNames()->first(),
                'status'    => $user->status,
            ]
        ], 201);
    }

    public function login(LoginRequest $request):JsonResponse
    {
        $validated = $request->validated();

        $user = User::where('email',$validated['login'])
                ->orWhere('phone',$validated['login'])
                ->first();


        if(!$user || !Hash::check($validated['password'],$user->password))
        {
            throw ValidationException::withMessages([
                 'login' => ['The provided credentials are incorrect.'],
            ]);
        }

        if($user->status === 'suspended')
        {
            return response()->json([
                'message' => 'Account is suspended',
                403
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;
        return response()->json([
            'message'       => 'Login successful.',
            'access_token'  => $token,
            'token_type'    => 'Bearer',
            'user' => [
                'id'        => $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'phone'     => $user->phone,
                'role'      => $user->getRoleNames()->first(),
                'status'    => $user->status,
            ]
        ], 200);
    }

    public function  me(Request $request):UserResource
    {
        $user = $request->user();

        return new UserResource($user);
    }

    public function logout(Request $request):JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ],200);
    }
}
