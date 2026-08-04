<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AiTriage\ProcessTriageRequest;
use App\Http\Resources\AiTriageResource;
use App\Models\AiTriageLog;
use Illuminate\Http\JsonResponse;

class AiTriageController extends Controller
{
    public function store(ProcessTriageRequest $request): JsonResponse
    {
        $validated  = $request->validated();
        $symptoms   = strtolower($validated['symptoms_summary']);

        // Heuristic symptom assessment engine
        $urgencyLevel       = 'low';
        $recommendation     = 'Rest, stay hydrated, and monitor your symptoms. Consult a general physician if symptoms persist for more than 48 hours.';
        if (str_contains($symptoms, 'chest pain') || str_contains($symptoms, 'difficulty breathing') || str_contains($symptoms, 'unconscious'))
        {
            $urgencyLevel   = 'emergency';
            $recommendation = 'EMERGENCY: Seek immediate medical attention or call emergency services right away.';
        }
        elseif (str_contains($symptoms, 'high fever') || str_contains($symptoms, 'severe pain') || str_contains($symptoms, 'bleeding'))
        {
            $urgencyLevel   = 'high';
            $recommendation = 'Urgent medical attention recommended. Book an immediate consultation with a specialist.';
        }
        elseif (str_contains($symptoms, 'cough') || str_contains($symptoms, 'headache') || str_contains($symptoms, 'fatigue'))
        {
            $urgencyLevel   = 'medium';
            $recommendation = 'Schedule a consultation with a General Practitioner for proper diagnosis.';
        }

        $log = AiTriageLog::create([
            'user_id'               => $request->user()->id,
            'symptoms_summary'      => $validated['symptoms_summary'],
            'urgency_level'         => $urgencyLevel,
            'recommended_action'    => $recommendation,
        ]);
        
        return response()->json([
            'message'   => 'Symptom triage completed.',
            'triage'    => new AiTriageResource($log),
        ], 201);
    }
}
