<?php

use App\Models\Appointment;
use App\Models\DoctorAvailability;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function triageReply(string $specialty, string $urgency = 'low'): array
{
    $text = json_encode(['specialty' => $specialty, 'urgency' => $urgency]);

    return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
}

function rankedDoctor(string $specialty, float $rating, int $experience, string $verification = 'verified'): User
{
    $doctor = makeDoctor($verification, $specialty);
    $doctor->doctorProfile->update(['rating' => $rating, 'experience_years' => $experience]);

    return $doctor;
}

function closeAllWeek(User $doctor): void
{
    foreach (range(0, 6) as $day) {
        DoctorAvailability::create([
            'doctor_user_id' => $doctor->id,
            'day_of_week' => $day,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'is_active' => false,
        ]);
    }
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['services.gemini.api_key' => 'test-key']);
    Http::preventStrayRequests();

    // Monday 08:00 UTC; with no availability configured a doctor works 09:00–17:00.
    $this->travelTo('2026-10-05 08:00:00');
    $this->patient = makePatient();
});

function searchSymptoms(string $query)
{
    return test()->actingAs(test()->patient, 'sanctum')
        ->postJson('/api/v1/symptom-search', ['query' => $query]);
}

describe('access', function () {
    it('requires authentication', function () {
        $this->postJson('/api/v1/symptom-search', ['query' => 'fever'])->assertUnauthorized();
    });

    it('requires a query of at most 500 characters', function ($query) {
        Http::fake();
        searchSymptoms($query)->assertUnprocessable()->assertJsonValidationErrors('query');
    })->with(['empty' => '', 'too long' => str_repeat('a', 501)]);
});

describe('red flags', function () {
    it('short-circuits to an emergency without calling the model', function (string $query, string $code, string $specialty) {
        Http::fake();

        searchSymptoms($query)
            ->assertOk()
            ->assertJsonPath('urgency', 'emergency')
            ->assertJsonPath('redFlag.code', $code)
            ->assertJsonPath('specialty', $specialty)
            ->assertJsonPath('source', 'rules');

        Http::assertNothingSent();
    })->with([
        ['I have had sharp chest pain since this morning', 'chest_pain', 'Cardiology'],
        ['গতকাল থেকে বুকে ব্যথা', 'chest_pain', 'Cardiology'],
        ["my father can't breathe properly", 'breathing', 'General Medicine'],
        ['her face is drooping and she has slurred speech', 'stroke', 'Neurology'],
        ['he is coughing up blood', 'bleeding', 'General Medicine'],
        ['I keep thinking about suicide', 'self_harm', 'Psychiatry'],
    ]);

    it('does not flag ordinary complaints', function () {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(triageReply('General Medicine'))]);

        searchSymptoms('Breathing problems and asthma')
            ->assertOk()
            ->assertJsonPath('redFlag', null)
            ->assertJsonPath('source', 'ai');
    });
});

describe('specialty mapping', function () {
    it('uses the model answer when it is on the fixed list', function () {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(triageReply('Dermatology', 'medium'))]);
        $derm = rankedDoctor('Dermatology', 4.5, 10);

        searchSymptoms('I have fever for last 3 days, and also red spots on my arms')
            ->assertOk()
            ->assertJsonPath('specialty', 'Dermatology')
            ->assertJsonPath('urgency', 'medium')
            ->assertJsonPath('source', 'ai')
            ->assertJsonPath('matchedSpecialty', true)
            ->assertJsonPath('doctors.0.id', $derm->id);

        Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', 'test-key')
            && $request['generationConfig']['responseSchema']['properties']['specialty']['enum'] === \App\Services\SymptomTriageService::SPECIALTIES);
    });

    it('falls back to keyword rules when the model answers off the list', function () {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(triageReply('Gastroenterology'))]);

        searchSymptoms('itchy rash on my arm')
            ->assertOk()
            ->assertJsonPath('specialty', 'Dermatology')
            ->assertJsonPath('urgency', null)
            ->assertJsonPath('source', 'fallback');
    });

    it('falls back to keyword rules when the model fails', function ($response) {
        Http::fake(['generativelanguage.googleapis.com/*' => $response]);

        searchSymptoms('pain in my left ear')
            ->assertOk()
            ->assertJsonPath('specialty', 'ENT')
            ->assertJsonPath('source', 'fallback');
    })->with([
        'server error' => fn () => Http::response('boom', 500),
        'connection failure' => fn () => Http::failedConnection(),
        'not json' => fn () => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Cardiology']]]]]]),
    ]);

    it('never calls the model without an API key', function () {
        config(['services.gemini.api_key' => null]);
        Http::fake();

        searchSymptoms('I feel feverish and tired')
            ->assertOk()
            ->assertJsonPath('specialty', 'General Medicine')
            ->assertJsonPath('source', 'fallback');

        Http::assertNothingSent();
    });
});

describe('ranking', function () {
    beforeEach(function () {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(triageReply('Cardiology'))]);
    });

    it('ranks a doctor free soon above a better-rated doctor with no free slots', function () {
        $soon = rankedDoctor('Cardiology', 4.0, 5);
        $busy = rankedDoctor('Cardiology', 5.0, 30);
        closeAllWeek($busy);
        foreach (range(1, 3) as $day) {
            Appointment::factory()->create([
                'doctor_user_id' => $busy->id,
                'patient_user_id' => $this->patient->id,
                'appointment_datetime' => "2026-09-0{$day} 10:00:00",
                'status' => 'completed',
            ]);
        }

        searchSymptoms('heart racing')
            ->assertOk()
            ->assertJsonCount(2, 'doctors')
            ->assertJsonPath('doctors.0.id', $soon->id)
            ->assertJsonPath('doctors.0.doctorProfile.nextAvailableAt', '2026-10-05T09:00:00+00:00')
            ->assertJsonPath('doctors.0.doctorProfile.completedConsultations', 0)
            ->assertJsonPath('doctors.1.id', $busy->id)
            ->assertJsonPath('doctors.1.doctorProfile.nextAvailableAt', null)
            ->assertJsonPath('doctors.1.doctorProfile.completedConsultations', 3);
    });

    it('skips booked and already-passed slots when finding the next free time', function () {
        $this->travelTo('2026-10-05 09:10:00');
        $doctor = rankedDoctor('Cardiology', 4.5, 10);
        bookAppointment($this->patient, $doctor)->update(['appointment_datetime' => '2026-10-05 09:30:00']);

        searchSymptoms('heart racing')
            ->assertOk()
            ->assertJsonPath('doctors.0.doctorProfile.nextAvailableAt', '2026-10-05T10:00:00+00:00');
    });

    it('breaks ties by doctor id so the order is stable', function () {
        $first = rankedDoctor('Cardiology', 4.5, 10);
        $second = rankedDoctor('Cardiology', 4.5, 10);

        searchSymptoms('heart racing')
            ->assertJsonPath('doctors.0.id', $first->id)
            ->assertJsonPath('doctors.1.id', $second->id);
    });

    it('excludes doctors who are not verified', function () {
        rankedDoctor('Cardiology', 5.0, 20, 'pending');
        $verified = rankedDoctor('Cardiology', 3.0, 2);

        searchSymptoms('heart racing')
            ->assertJsonCount(1, 'doctors')
            ->assertJsonPath('doctors.0.id', $verified->id);
    });

    it('falls back to General Medicine when no doctor practises the specialty', function () {
        $gp = rankedDoctor('General Medicine', 4.0, 8);

        searchSymptoms('heart racing')
            ->assertOk()
            ->assertJsonPath('specialty', 'Cardiology')
            ->assertJsonPath('matchedSpecialty', false)
            ->assertJsonPath('doctors.0.id', $gp->id);
    });

    it('does not add ranking fields to the regular doctor listing', function () {
        rankedDoctor('Cardiology', 4.0, 5);

        $profile = $this->getJson('/api/v1/doctors')->assertOk()->json('data.0.doctorProfile');

        expect($profile)->not->toHaveKeys(['completedConsultations', 'nextAvailableAt']);
    });
});

describe('slots endpoint (shared slot logic)', function () {
    it('still lists the day\'s slots and marks booked ones', function () {
        $doctor = rankedDoctor('Cardiology', 4.0, 5);
        bookAppointment($this->patient, $doctor)->update(['appointment_datetime' => '2026-10-05 09:30:00']);

        $this->getJson("/api/v1/doctors/{$doctor->id}/slots?date=2026-10-05")
            ->assertOk()
            ->assertJsonPath('date', '2026-10-05')
            ->assertJsonPath('slotDuration', 30)
            ->assertJsonCount(16, 'slots')
            ->assertJsonPath('slots.0', ['time' => '09:00', 'datetime' => '2026-10-05T09:00:00+00:00', 'available' => true])
            ->assertJsonPath('slots.1.available', false)
            ->assertJsonMissingPath('note');
    });

    it('still reports a day off', function () {
        $doctor = rankedDoctor('Cardiology', 4.0, 5);

        $this->getJson("/api/v1/doctors/{$doctor->id}/slots?date=2026-10-09") // Friday
            ->assertOk()
            ->assertJsonPath('slots', [])
            ->assertJsonPath('note', 'Doctor is not available on this day.');
    });
});
