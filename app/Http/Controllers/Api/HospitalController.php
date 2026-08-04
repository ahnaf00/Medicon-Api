<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\HospitalResource;
use App\Models\Hospital;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HospitalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Hospital::query();
        if ($request->has('search')) {
            $search = $request->query('search');
            $query->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('address', 'LIKE', "%{$search}%");
        }
        $hospitals = $query->orderBy('name', 'asc')->get();
        return HospitalResource::collection($hospitals);
    }
}
