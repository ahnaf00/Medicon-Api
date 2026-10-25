<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\HospitalResource;
use App\Models\Hospital;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HospitalController extends Controller
{
    /** Hospitals patients can see: hidden ones are left out (audit L2b step 3). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = trim((string) $request->query('search', ''));

        $hospitals = Hospital::active()
            ->when($search !== '', fn ($q) => $q->matching($search))
            ->orderBy('name')
            ->get();

        return HospitalResource::collection($hospitals);
    }
}
