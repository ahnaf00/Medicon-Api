<?php

use App\Models\Appointment;
use App\Models\User;
use App\Services\LiveKitService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const LK_SECRET = 'test-livekit-secret-0123456789abcdef';

beforeEach(function () {
    config([
        'services.livekit.url' => 'ws://192.168.0.10:7880',
        'services.livekit.http_url' => 'http://livekit.test',
        'services.livekit.api_key' => 'devkey',
        'services.livekit.api_secret' => LK_SECRET,
    ]);

    $this->livekit = app(LiveKitService::class);
    $this->user = (new User)->forceFill(['id' => 17, 'name' => 'Dr. Rahman']);
    $this->appointment = (new Appointment)->forceFill(['id' => 42]);
});

function decodeLiveKit(string $jwt): array
{
    return json_decode(json_encode(JWT::decode($jwt, new Key(LK_SECRET, 'HS256'))), true);
}

it('mints a participant token scoped to the appointment room', function () {
    $claims = decodeLiveKit($this->livekit->token($this->user, $this->appointment, 'doctor', true));

    expect($claims['iss'])->toBe('devkey')
        ->and($claims['sub'])->toBe('user-17')
        ->and($claims['name'])->toBe('Dr. Rahman')
        ->and($claims['exp'] - $claims['nbf'])->toBe(2 * 60 * 60)
        ->and($claims['video'])->toBe([
            'room' => 'appointment-42',
            'roomJoin' => true,
            'canPublish' => true,
            'canSubscribe' => true,
        ])
        ->and($claims['video'])->not->toHaveKey('canUpdateOwnMetadata')
        ->and($claims['video'])->not->toHaveKey('roomAdmin')
        ->and($claims['attributes'])->toBe(['role' => 'doctor', 'consent' => 'true']);
});

it('encodes declined consent and the patient role as strings', function () {
    $claims = decodeLiveKit($this->livekit->token($this->user, $this->appointment, 'patient', false));

    expect($claims['attributes'])->toBe(['role' => 'patient', 'consent' => 'false']);
});

it('rejects an unknown role', function () {
    $this->livekit->token($this->user, $this->appointment, 'agent', true);
})->throws(InvalidArgumentException::class);

it('refuses a secret too short for HS256', function () {
    config(['services.livekit.api_secret' => 'secret']);

    $this->livekit->token($this->user, $this->appointment, 'doctor', true);
})->throws(RuntimeException::class, 'at least 32 characters');

it('refuses to sign without credentials', function () {
    config(['services.livekit.api_key' => null]);

    $this->livekit->token($this->user, $this->appointment, 'doctor', true);
})->throws(RuntimeException::class, 'api_key is not configured');

it('pushes consent through RoomService/UpdateParticipant', function () {
    Http::fake(['livekit.test/*' => Http::response(['identity' => 'user-17'])]);

    expect($this->livekit->setConsent('appointment-42', 'user-17', false))->toBeTrue();

    Http::assertSent(function (Request $request) {
        $token = decodeLiveKit(substr($request->header('Authorization')[0], 7));

        return $request->url() === 'http://livekit.test/twirp/livekit.RoomService/UpdateParticipant'
            && $request->method() === 'POST'
            && $request->data() === [
                'room' => 'appointment-42',
                'identity' => 'user-17',
                'attributes' => ['consent' => 'false'],
            ]
            && $token['video'] === ['room' => 'appointment-42', 'roomAdmin' => true];
    });
});

it('ignores a participant who is not in the room', function () {
    Http::fake(['livekit.test/*' => Http::response(['code' => 'not_found', 'msg' => 'participant not found'], 404)]);

    expect($this->livekit->setConsent('appointment-42', 'user-17', true))->toBeFalse();
});

it('surfaces any other consent failure instead of swallowing it', function () {
    Http::fake(['livekit.test/*' => Http::response(['code' => 'internal', 'msg' => 'boom'], 500)]);

    $this->livekit->setConsent('appointment-42', 'user-17', false);
})->throws(RuntimeException::class, 'UpdateParticipant failed (500): boom');

it('deletes the room through RoomService/DeleteRoom', function () {
    Http::fake(['livekit.test/*' => Http::response([])]);

    $this->livekit->deleteRoom('appointment-42');

    Http::assertSent(function (Request $request) {
        $token = decodeLiveKit(substr($request->header('Authorization')[0], 7));

        return $request->url() === 'http://livekit.test/twirp/livekit.RoomService/DeleteRoom'
            && $request->data() === ['room' => 'appointment-42']
            && $token['video'] === ['roomCreate' => true];
    });
});

it('treats deleting a room that is already gone as done', function () {
    Http::fake(['livekit.test/*' => Http::response(['code' => 'not_found', 'msg' => 'room not found'], 404)]);

    $this->livekit->deleteRoom('appointment-42');

    Http::assertSentCount(1);
});

it('throws when the room cannot be deleted', function () {
    Http::fake(['livekit.test/*' => Http::response(['code' => 'unavailable'], 503)]);

    $this->livekit->deleteRoom('appointment-42');
})->throws(RuntimeException::class, 'DeleteRoom failed (503)');
