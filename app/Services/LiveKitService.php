<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Talks to the self-hosted LiveKit server used for video consultations.
 *
 * - `token()` mints the access token a phone uses to join `appointment-{id}`.
 *   The grant deliberately omits `canUpdateOwnMetadata`, so a client can never
 *   change its own `consent` attribute; only `setConsent()` (server side) can.
 * - `setConsent()` / `deleteRoom()` call LiveKit's Twirp RoomService API.
 */
class LiveKitService
{
    private const TOKEN_TTL_SECONDS = 2 * 60 * 60;

    private const ADMIN_TOKEN_TTL_SECONDS = 60;

    public function roomName(Appointment $appointment): string
    {
        return 'appointment-'.$appointment->getKey();
    }

    public function identity(User $user): string
    {
        return 'user-'.$user->getKey();
    }

    public function url(): string
    {
        return (string) config('services.livekit.url');
    }

    /**
     * Access token for one participant, limited to this appointment's room.
     */
    public function token(User $user, Appointment $appointment, string $role, bool $consent): string
    {
        if (! in_array($role, ['doctor', 'patient'], true)) {
            throw new InvalidArgumentException("Unknown call role [{$role}].");
        }

        $now = time();

        return $this->sign([
            'iss' => $this->apiKey(),
            'sub' => $this->identity($user),
            'name' => (string) $user->name,
            'nbf' => $now,
            'exp' => $now + self::TOKEN_TTL_SECONDS,
            'video' => [
                'room' => $this->roomName($appointment),
                'roomJoin' => true,
                'canPublish' => true,
                'canSubscribe' => true,
            ],
            // LiveKit attributes are string => string.
            'attributes' => [
                'role' => $role,
                'consent' => $consent ? 'true' : 'false',
            ],
        ]);
    }

    /**
     * Push a participant's consent to the live room.
     *
     * Returns false when the participant is not in the room (or the room does
     * not exist yet): the stored consent is applied by the next token instead.
     * Any other failure throws, because silently ignoring a withdrawal would
     * let recording continue.
     */
    public function setConsent(string $room, string $identity, bool $consent): bool
    {
        $response = $this->roomService('UpdateParticipant', [
            'room' => $room,
            'identity' => $identity,
            'attributes' => ['consent' => $consent ? 'true' : 'false'],
        ], ['room' => $room, 'roomAdmin' => true]);

        if ($this->isNotFound($response)) {
            return false;
        }

        $this->throwUnlessSuccessful($response, 'UpdateParticipant');

        return true;
    }

    /**
     * Close the room for everyone. A room that is already gone is not an error.
     */
    public function deleteRoom(string $room): void
    {
        $response = $this->roomService('DeleteRoom', ['room' => $room], ['roomCreate' => true]);

        if ($this->isNotFound($response)) {
            return;
        }

        $this->throwUnlessSuccessful($response, 'DeleteRoom');
    }

    private function roomService(string $method, array $body, array $videoGrant): Response
    {
        $now = time();
        $token = $this->sign([
            'iss' => $this->apiKey(),
            'nbf' => $now,
            'exp' => $now + self::ADMIN_TOKEN_TTL_SECONDS,
            'video' => $videoGrant,
        ]);

        return $this->http()
            ->withToken($token)
            ->post("/twirp/livekit.RoomService/{$method}", $body);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.livekit.http_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->timeout(5);
    }

    private function isNotFound(Response $response): bool
    {
        return $response->status() === 404 || $response->json('code') === 'not_found';
    }

    private function throwUnlessSuccessful(Response $response, string $method): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'LiveKit %s failed (%d): %s',
            $method,
            $response->status(),
            $response->json('msg') ?? $response->body(),
        ));
    }

    private function sign(array $claims): string
    {
        return JWT::encode($claims, $this->apiSecret(), 'HS256');
    }

    private function apiKey(): string
    {
        return $this->requireConfig('api_key');
    }

    private function apiSecret(): string
    {
        $secret = $this->requireConfig('api_secret');

        // firebase/php-jwt refuses HS256 keys under 256 bits, so LiveKit's
        // --dev default ("secret") cannot be used; start LiveKit with --keys.
        if (strlen($secret) < 32) {
            throw new RuntimeException('services.livekit.api_secret must be at least 32 characters.');
        }

        return $secret;
    }

    private function requireConfig(string $key): string
    {
        $value = config("services.livekit.{$key}");
        if (empty($value)) {
            throw new RuntimeException("services.livekit.{$key} is not configured.");
        }

        return (string) $value;
    }
}
