<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MedicalRecords\CheckInteractionRequest;
use App\Services\OpenFdaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MedicineController extends Controller
{
    public function __construct(private readonly OpenFdaService $fdaService) {}

    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        $results = $this->fdaService->searchDrug($request->query('q'));

        return response()->json(['results' => $results]);
    }

    public function checkInteractions(CheckInteractionRequest $request): JsonResponse
    {
        $names = collect($request->validated('medicines'))->pluck('name')->toArray();

        $result = $this->fdaService->checkInteractions($names);

        return response()->json($result);
    }
}
