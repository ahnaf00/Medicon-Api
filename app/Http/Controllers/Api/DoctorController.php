<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorController extends Controller
{
    public function index(Request $request):JsonResponse
    {
        $query = User::role('doctor')
                ->whereHas('doctorProfile',function ($q){
                    $q->where('verification_status','verfied');
                })->with('doctorProfile');

        if($request->has('speciality'))
        {
            $query->whereHas('doctorProfile', function($q) use ($request){
                $q->where('speciality','LIKE','%'.$request->query('speciality').'%');
            });
        }

        $doctors = $query->paginate(15);
        return response()->json([
            $doctors,200
        ]);
    }

    public function show(int $id):JsonResponse
    {
        $doctor = User::role('doctor')
                ->with('doctorProfile')
                ->findOrFail($id);

        return response()->json(['doctor' => $doctor]);
    }
}
