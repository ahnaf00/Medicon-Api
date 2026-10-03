<?php

use App\Models\Conversation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->patient = makePatient();
    $this->doctor = makeDoctor('verified', 'Cardiology');
});

function askQuestion($test, bool $anonymous = false): Conversation
{
    $id = $test->actingAs($test->patient, 'sanctum')
        ->postJson('/api/v1/conversations', [
            'department' => 'Cardiology',
            'subject' => 'Chest tightness after running',
            'is_anonymous' => $anonymous,
        ])
        ->assertCreated()
        ->json('conversation.id');

    $test->postJson("/api/v1/conversations/{$id}/messages", ['body' => 'Chest tightness after running'])
        ->assertCreated();

    return Conversation::findOrFail($id);
}

describe('anonymous questions (task 5.1)', function () {
    it('stores the flag', function () {
        expect(askQuestion($this, true)->is_anonymous)->toBeTrue()
            ->and(askQuestion($this)->is_anonymous)->toBeFalse();
    });

    it("hides the patient's identity from doctors in the inbox", function () {
        askQuestion($this, true);

        $row = $this->actingAs($this->doctor, 'sanctum')
            ->getJson('/api/v1/conversations')
            ->assertOk()
            ->json('data.0');

        expect($row['isAnonymous'])->toBeTrue()
            ->and($row['patient'])->toBeNull()
            ->and($row['firstMessage']['sender'])->toBeNull()
            ->and($row['firstMessage']['fromPatient'])->toBeTrue()
            ->and(json_encode($row))->not->toContain($this->patient->name);
    });

    it("hides the patient's identity in the thread but keeps the doctor's", function () {
        $question = askQuestion($this, true);

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/v1/conversations/{$question->id}/messages", ['body' => 'Please get an ECG.'])
            ->assertCreated();

        $this->getJson('/api/v1/conversations')->assertJsonPath('data.0.replyCount', 1);

        $messages = $this->getJson("/api/v1/conversations/{$question->id}/messages")
            ->assertOk()
            ->json('data');

        expect($messages[0]['sender'])->toBeNull()
            ->and($messages[0]['fromPatient'])->toBeTrue()
            ->and($messages[1]['sender']['id'])->toBe($this->doctor->id)
            ->and($messages[1]['fromPatient'])->toBeFalse();
    });

    it('still shows the patient their own identity', function () {
        askQuestion($this, true);

        $row = $this->actingAs($this->patient, 'sanctum')
            ->getJson('/api/v1/conversations')
            ->assertOk()
            ->json('data.0');

        expect($row['patient']['id'])->toBe($this->patient->id);
    });

    it('shows the patient to the doctor when not anonymous', function () {
        askQuestion($this);

        $this->actingAs($this->doctor, 'sanctum')
            ->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.patient.id', $this->patient->id);
    });
});

describe('editing and deleting questions (task 5.2)', function () {
    it('lets the patient edit an unanswered question', function () {
        $question = askQuestion($this);

        $this->actingAs($this->patient, 'sanctum')
            ->patchJson("/api/v1/conversations/{$question->id}", [
                'body' => 'Chest pain when climbing stairs',
                'is_anonymous' => true,
            ])
            ->assertOk()
            ->assertJsonPath('conversation.firstMessage.body', 'Chest pain when climbing stairs')
            ->assertJsonPath('conversation.isAnonymous', true);
    });

    it('lets the patient delete an unanswered question', function () {
        $question = askQuestion($this);

        $this->actingAs($this->patient, 'sanctum')
            ->deleteJson("/api/v1/conversations/{$question->id}")
            ->assertOk();

        expect(Conversation::find($question->id))->toBeNull();
    });

    it('refuses edits and deletion once a doctor has replied', function () {
        $question = askQuestion($this);

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/v1/conversations/{$question->id}/messages", ['body' => 'Please get an ECG.'])
            ->assertCreated();

        $this->actingAs($this->patient, 'sanctum')
            ->patchJson("/api/v1/conversations/{$question->id}", ['body' => 'Something else'])
            ->assertForbidden();

        $this->deleteJson("/api/v1/conversations/{$question->id}")->assertForbidden();

        expect(Conversation::find($question->id))->not->toBeNull();
    });

    it("refuses another patient or a doctor editing or deleting someone's question", function () {
        $question = askQuestion($this);

        foreach ([makePatient(), $this->doctor] as $other) {
            $this->actingAs($other, 'sanctum')
                ->patchJson("/api/v1/conversations/{$question->id}", ['body' => 'x'])
                ->assertForbidden();

            $this->deleteJson("/api/v1/conversations/{$question->id}")->assertForbidden();
        }
    });
});

describe('editing an answer (task 5.2)', function () {
    beforeEach(function () {
        $this->question = askQuestion($this);

        $this->answerId = $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/v1/conversations/{$this->question->id}/messages", ['body' => 'Please get an ECG.'])
            ->assertCreated()
            ->json('data.id');
    });

    it('lets the doctor edit their own answer', function () {
        $this->actingAs($this->doctor, 'sanctum')
            ->patchJson("/api/v1/conversations/{$this->question->id}/messages/{$this->answerId}", [
                'body' => 'Please get an ECG and a lipid profile.',
            ])
            ->assertOk()
            ->assertJsonPath('data.body', 'Please get an ECG and a lipid profile.');

        expect($this->question->messages()->count())->toBe(2);
    });

    it("refuses the patient or another doctor editing the doctor's answer", function () {
        foreach ([$this->patient, makeDoctor('verified', 'Cardiology')] as $other) {
            $this->actingAs($other, 'sanctum')
                ->patchJson("/api/v1/conversations/{$this->question->id}/messages/{$this->answerId}", ['body' => 'x'])
                ->assertForbidden();
        }
    });

    it('404s for a message from a different conversation', function () {
        $other = Conversation::create([
            'patient_user_id' => $this->patient->id,
            'doctor_user_id' => $this->doctor->id,
            'department' => 'Cardiology',
            'status' => 'open',
        ]);

        $this->actingAs($this->doctor, 'sanctum')
            ->patchJson("/api/v1/conversations/{$other->id}/messages/{$this->answerId}", ['body' => 'x'])
            ->assertNotFound();
    });
});
