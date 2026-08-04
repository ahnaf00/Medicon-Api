<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DoctorController extends Controller
{
    public function index():AnonymousResourceCollection
    {
        $doctors = User::role('doctor')->with('doctorProfile')->get();

        return UserResource::collection($doctors);
    }

    public function show(int $id):UserResource
    {
        $doctor = User::role('doctor')
                ->with('doctorProfile')
                ->findOrFail($id);

            return new UserResource($doctor);
        }
}
