<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\HospitalResource;
use App\Models\Hospital;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HospitalController extends Controller
{
    /**
     * Hospitals patients can see: hidden ones are left out (audit L2b step 3).
     * A-Z, 20 per page (audit DB-003 / H3).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $search = trim((string) $request->query('search', ''));

        $hospitals = Hospital::active()
            ->when($search !== '', fn ($q) => $q->matching($search))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->integer('per_page', 20));

        return HospitalResource::collection($hospitals);
    }

    /** One visible hospital, so the details screen no longer searches the whole list. */
    public function show(int $id): HospitalResource
    {
        return new HospitalResource(Hospital::active()->findOrFail($id));
    }
}
