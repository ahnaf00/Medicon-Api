<?php

use App\Models\ConsultationSummary;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->patient = makePatient();
    $this->doctor = makeDoctor();
    $this->appointment = bookAppointment($this->patient, $this->doctor);
    $this->appointment->update(['status' => 'in_progress']);
});

function summaryPayload(array $overrides = []): array
{
    return $overrides + [
        'chief_complaint' => 'Chest pain after running',
        'findings' => 'ECG normal. BP 130/85.',
        'advice' => 'Aspirin 75 mg daily; avoid strenuous exercise for two weeks.',
        'red_flags' => ['Pain at rest lasting over 15 minutes', 'Fainting'],
    ];
}

function putSummary($user, $appointment, array $payload)
{
    return test()->actingAs($user, 'sanctum')
        ->putJson("/api/v1/consultations/{$appointment->id}/summary", $payload);
}

describe('writing', function () {
    it('lets the appointment\'s doctor create and then edit the summary', function () {
        putSummary($this->doctor, $this->appointment, summaryPayload())
            ->assertCreated()
            ->assertJsonPath('summary.chiefComplaint', 'Chest pain after running')
            ->assertJsonPath('summary.redFlags', ['Pain at rest lasting over 15 minutes', 'Fainting'])
            ->assertJsonPath('summary.source', 'doctor_note')
            ->assertJsonPath('doctor.name', $this->doctor->name);

        $this->appointment->update(['status' => 'completed']);

        putSummary($this->doctor, $this->appointment, summaryPayload(['advice' => 'Updated advice', 'red_flags' => []]))
            ->assertOk()
            ->assertJsonPath('summary.advice', 'Updated advice')
            ->assertJsonPath('summary.redFlags', []);

        $summary = ConsultationSummary::sole();
        expect($summary->patient_user_id)->toBe($this->patient->id)
            ->and($summary->doctor_user_id)->toBe($this->doctor->id);
    });

    it('refuses before the consultation has started', function (string $status) {
        $this->appointment->update(['status' => $status]);

        putSummary($this->doctor, $this->appointment, summaryPayload())->assertStatus(409);
        expect(ConsultationSummary::count())->toBe(0);
    })->with(['scheduled', 'cancelled', 'no_show']);

    it('refuses other doctors and patients', function () {
        putSummary(makeDoctor(), $this->appointment, summaryPayload())->assertForbidden();
        putSummary($this->patient, $this->appointment, summaryPayload())->assertForbidden();

        expect(ConsultationSummary::count())->toBe(0);
    });

    it('requires a chief complaint and caps red flags', function () {
        putSummary($this->doctor, $this->appointment, summaryPayload(['chief_complaint' => '']))
            ->assertJsonValidationErrors('chief_complaint');
        putSummary($this->doctor, $this->appointment, summaryPayload(['red_flags' => array_fill(0, 11, 'x')]))
            ->assertJsonValidationErrors('red_flags');
    });
});

describe('reading', function () {
    it('shows a shared summary to both participants', function () {
        $this->appointment->update(['status' => 'completed']);
        putSummary($this->doctor, $this->appointment, summaryPayload(['publish' => true]))->assertCreated();

        foreach ([$this->patient, $this->doctor] as $user) {
            $this->actingAs($user, 'sanctum')
                ->getJson("/api/v1/consultations/{$this->appointment->id}/summary")
                ->assertOk()
                ->assertJsonPath('appointment.id', $this->appointment->id)
                ->assertJsonPath('summary.findings', 'ECG normal. BP 130/85.');
        }
    });

    it('returns a null summary until the doctor writes one', function () {
        $this->actingAs($this->patient, 'sanctum')
            ->getJson("/api/v1/consultations/{$this->appointment->id}/summary")
            ->assertOk()
            ->assertJsonPath('summary', null)
            ->assertJsonPath('doctor.id', $this->doctor->id);
    });

    it('hides the summary from anyone else', function () {
        $this->actingAs(makePatient(), 'sanctum')
            ->getJson("/api/v1/consultations/{$this->appointment->id}/summary")
            ->assertForbidden();
    });

    it('flags which appointments have a summary in the list', function () {
        $this->appointment->update(['appointment_datetime' => '2026-10-05 03:00:00', 'status' => 'completed']);
        putSummary($this->doctor, $this->appointment, summaryPayload(['publish' => true]))->assertCreated();
        bookAppointment($this->patient, $this->doctor)->update(['appointment_datetime' => '2026-10-06 03:00:00']);

        // The list is newest first (H3): the 6 Oct visit, then the 5 Oct one with the summary.
        $this->actingAs($this->patient, 'sanctum')
            ->getJson('/api/v1/appointments')
            ->assertOk()
            ->assertJsonPath('data.0.hasSummary', false)
            ->assertJsonPath('data.1.hasSummary', true);
    });
});

// Audit G7 (REC-004): a summary is a draft until the doctor shares it.
describe('publishing', function () {
    it('keeps a draft from the patient, the list flag and the AI chat', function () {
        putSummary($this->doctor, $this->appointment, summaryPayload())
            ->assertCreated()
            ->assertJsonPath('summary.publishedAt', null);
        $this->appointment->update(['status' => 'completed']);

        $this->actingAs($this->patient, 'sanctum')
            ->getJson("/api/v1/consultations/{$this->appointment->id}/summary")
            ->assertOk()
            ->assertJsonPath('summary', null);
        $this->actingAs($this->patient, 'sanctum')
            ->getJson('/api/v1/appointments')
            ->assertJsonPath('data.0.hasSummary', false);
        $this->actingAs($this->patient, 'sanctum')
            ->postJson('/api/v1/ai/consultation-chat', ['appointment_id' => $this->appointment->id, 'message' => 'What should I do?'])
            ->assertStatus(409);

        // The doctor still sees and edits the draft.
        $this->actingAs($this->doctor, 'sanctum')
            ->getJson("/api/v1/consultations/{$this->appointment->id}/summary")
            ->assertJsonPath('summary.chiefComplaint', 'Chest pain after running');
        $this->actingAs($this->doctor, 'sanctum')
            ->getJson('/api/v1/appointments')
            ->assertJsonPath('data.0.hasSummary', true);
    });

    it('shares only once the visit is completed', function () {
        putSummary($this->doctor, $this->appointment, summaryPayload(['publish' => true]))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Complete the consultation before sharing the summary with the patient.');
        expect(ConsultationSummary::count())->toBe(0);

        $this->appointment->update(['status' => 'completed']);
        putSummary($this->doctor, $this->appointment, summaryPayload(['publish' => true]))
            ->assertCreated()
            ->assertJsonPath('summary.publishedAt', now()->toIso8601String());

        $this->actingAs($this->patient, 'sanctum')
            ->getJson("/api/v1/consultations/{$this->appointment->id}/summary")
            ->assertJsonPath('summary.chiefComplaint', 'Chest pain after running');
    });

    it('keeps a shared summary shared when the doctor edits it', function () {
        $this->appointment->update(['status' => 'completed']);
        putSummary($this->doctor, $this->appointment, summaryPayload(['publish' => true]))->assertCreated();
        $sharedAt = ConsultationSummary::sole()->published_at;

        $this->travel(1)->hours();
        putSummary($this->doctor, $this->appointment, summaryPayload(['advice' => 'Rest for a week']))->assertOk();

        expect(ConsultationSummary::sole()->published_at->equalTo($sharedAt))->toBeTrue();
        $this->actingAs($this->patient, 'sanctum')
            ->getJson("/api/v1/consultations/{$this->appointment->id}/summary")
            ->assertJsonPath('summary.advice', 'Rest for a week');
    });
});
