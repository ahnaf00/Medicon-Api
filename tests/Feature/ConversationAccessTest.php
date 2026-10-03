<?php

use App\Models\Conversation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->patient = makePatient();
    $this->question = Conversation::create([
        'patient_user_id' => $this->patient->id,
        'doctor_user_id' => null,
        'department' => 'Cardiology',
        'subject' => 'Chest tightness after running',
        'status' => 'open',
    ]);
    $this->question->messages()->create([
        'sender_user_id' => $this->patient->id,
        'body' => 'Chest tightness after running',
    ]);
});

describe('reading an unassigned question', function () {
    it('lets the asking patient read it', function () {
        $this->actingAs($this->patient, 'sanctum')
            ->getJson("/api/v1/conversations/{$this->question->id}/messages")
            ->assertOk();
    });

    it('lets a verified doctor in the matching department read it', function () {
        $this->actingAs(makeDoctor('verified', 'Cardiology'), 'sanctum')
            ->getJson("/api/v1/conversations/{$this->question->id}/messages")
            ->assertOk();
    });

    it('forbids another patient', function () {
        $this->actingAs(makePatient(), 'sanctum')
            ->getJson("/api/v1/conversations/{$this->question->id}/messages")
            ->assertForbidden();
    });

    it('forbids a doctor from another department', function () {
        $this->actingAs(makeDoctor('verified', 'Dermatology'), 'sanctum')
            ->getJson("/api/v1/conversations/{$this->question->id}/messages")
            ->assertForbidden();
    });

    it('forbids an unverified doctor in the matching department', function () {
        $this->actingAs(makeDoctor('pending', 'Cardiology'), 'sanctum')
            ->getJson("/api/v1/conversations/{$this->question->id}/messages")
            ->assertForbidden();
    });
});

describe('claiming and replying', function () {
    it('forbids another patient from replying or claiming', function () {
        $this->actingAs(makePatient(), 'sanctum')
            ->postJson("/api/v1/conversations/{$this->question->id}/messages", ['body' => 'hi'])
            ->assertForbidden();

        expect($this->question->fresh()->doctor_user_id)->toBeNull();
    });

    it('lets a matching doctor claim by replying, then locks out other doctors', function () {
        $doctor = makeDoctor('verified', 'Cardiology');

        $this->actingAs($doctor, 'sanctum')
            ->postJson("/api/v1/conversations/{$this->question->id}/messages", ['body' => 'Please get an ECG.'])
            ->assertCreated();

        expect($this->question->fresh()->doctor_user_id)->toBe($doctor->id);

        $this->actingAs(makeDoctor('verified', 'Cardiology'), 'sanctum')
            ->getJson("/api/v1/conversations/{$this->question->id}/messages")
            ->assertForbidden();
    });
});

describe('inbox listing', function () {
    it('does not leak unassigned questions to a patient passing ?department', function () {
        $this->actingAs(makePatient(), 'sanctum')
            ->getJson('/api/v1/conversations?department=Cardiology')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it("scopes a doctor's inbox to their own specialty, ignoring ?department", function () {
        $this->actingAs(makeDoctor('verified', 'Dermatology'), 'sanctum')
            ->getJson('/api/v1/conversations?department=Cardiology')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs(makeDoctor('verified', 'Cardiology'), 'sanctum')
            ->getJson('/api/v1/conversations?department=Cardiology')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });
});
