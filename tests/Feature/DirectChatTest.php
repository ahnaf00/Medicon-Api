<?php

use App\Models\Conversation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Audit M1: direct doctor-patient chat on top of conversations + messages.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->patient = makePatient();
    $this->doctor = makeDoctor();
    $this->visit = bookAppointment($this->patient, $this->doctor);
});

function openChat($user, $otherId)
{
    return test()->actingAs($user, 'sanctum')->postJson('/api/v1/chats', ['user_id' => $otherId]);
}

function chatSend($user, int $chatId, string $body)
{
    return test()->actingAs($user, 'sanctum')->postJson("/api/v1/conversations/{$chatId}/messages", ['body' => $body]);
}

describe('opening a chat', function () {
    it('gives the patient and the doctor the same one chat', function () {
        $id = openChat($this->patient, $this->doctor->id)
            ->assertCreated()
            ->assertJsonPath('conversation.type', 'direct')
            ->assertJsonPath('conversation.doctor.id', $this->doctor->id)
            ->json('conversation.id');

        openChat($this->patient, $this->doctor->id)->assertOk()->assertJsonPath('conversation.id', $id);
        openChat($this->doctor, $this->patient->id)->assertOk()->assertJsonPath('conversation.id', $id);
        expect(Conversation::count())->toBe(1);
    });

    it('needs a visit together', function () {
        openChat($this->patient, makeDoctor()->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'You can message a doctor after booking a visit with them.');
        openChat($this->doctor, makePatient()->id)->assertForbidden();
        openChat($this->patient, $this->patient->id)->assertForbidden();

        $this->visit->update(['status' => 'cancelled']);
        openChat($this->patient, $this->doctor->id)->assertForbidden();
        expect(Conversation::count())->toBe(0);
    });
});

describe('messages', function () {
    it('lets both sides write and read, and marks messages read', function () {
        $id = openChat($this->patient, $this->doctor->id)->json('conversation.id');

        chatSend($this->patient, $id, 'Should I keep taking the syrup?')->assertCreated();
        chatSend($this->doctor, $id, 'Yes, for three more days.')->assertCreated();

        $this->actingAs($this->doctor, 'sanctum')->getJson('/api/v1/chats')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.unreadCount', 1)
            ->assertJsonPath('data.0.latestMessage.body', 'Yes, for three more days.');

        $this->actingAs($this->doctor, 'sanctum')->getJson("/api/v1/conversations/{$id}/messages")
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->actingAs($this->doctor, 'sanctum')->getJson('/api/v1/chats')
            ->assertJsonPath('data.0.unreadCount', 0);
        $this->actingAs($this->patient, 'sanctum')->getJson('/api/v1/chats')
            ->assertJsonPath('data.0.unreadCount', 1);
    });

    it('keeps everyone else out, including doctors of the same specialty', function () {
        $id = openChat($this->patient, $this->doctor->id)->json('conversation.id');
        chatSend($this->patient, $id, 'Private question')->assertCreated();

        foreach ([makePatient(), makeDoctor()] as $stranger) {
            $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/conversations/{$id}/messages")->assertForbidden();
            chatSend($stranger, $id, 'Hello')->assertForbidden();
            $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/chats')->assertOk()->assertJsonCount(0, 'data');
        }
    });

    it('cannot be edited or deleted like a Q&A question', function () {
        $id = openChat($this->patient, $this->doctor->id)->json('conversation.id');
        chatSend($this->patient, $id, 'Hello doctor')->assertCreated();

        $this->actingAs($this->patient, 'sanctum')->patchJson("/api/v1/conversations/{$id}", ['body' => 'Changed'])->assertForbidden();
        $this->actingAs($this->patient, 'sanctum')->deleteJson("/api/v1/conversations/{$id}")->assertForbidden();
    });
});

it('keeps chats out of the Q&A lists', function () {
    openChat($this->patient, $this->doctor->id)->assertCreated();

    $this->actingAs($this->patient, 'sanctum')->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($this->doctor, 'sanctum')->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(0, 'data');
});
