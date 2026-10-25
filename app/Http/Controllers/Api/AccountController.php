<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\PhoneOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Account deletion, restore and data export (audit L3).
 */
class AccountController extends Controller
{
    private const RESTORE_TTL_MINUTES = 10;

    /** Sends a code to the account's own phone; deleting needs it. */
    public function sendDeletionCode(Request $request, PhoneOtpService $otps): JsonResponse
    {
        $user = $request->user();

        if ($refusal = $this->refuse($user)) {
            return $refusal;
        }
        if (! $otps->canSend()) {
            return AuthController::otpUnavailable();
        }

        $otps->issue($user->phone);

        return AuthController::otpSent();
    }

    public function destroy(Request $request, PhoneOtpService $otps, AccountDeletionService $accounts): JsonResponse
    {
        $validated = $request->validate(['otp' => ['required', 'string', 'size:6']]);
        $user = $request->user();

        if ($refusal = $this->refuse($user)) {
            return $refusal;
        }

        $result = $otps->check($user->phone, $validated['otp']);
        if ($result !== PhoneOtpService::OK) {
            [$message, $status] = PhoneOtpService::failure($result);

            return response()->json(['message' => $message], $status);
        }

        $accounts->requestDeletion($user);

        return response()->json([
            'message' => 'Your account is deleted. Sign in within '.AccountDeletionService::GRACE_DAYS.' days to restore it.',
            'purgeAt' => $accounts->purgeDate($user)?->toIso8601String(),
        ]);
    }

    /** Called with the token verify-otp gives a deleted account still in its grace period. */
    public function restore(Request $request, AccountDeletionService $accounts): JsonResponse
    {
        $validated = $request->validate(['restore_token' => ['required', 'string']]);
        $userId = Cache::pull(self::restoreKey($validated['restore_token']));
        $user = $userId ? User::onlyTrashed()->whereNull('purged_at')->find($userId) : null;

        if (! $user) {
            return response()->json(['message' => 'This restore link has expired. Sign in again.'], 422);
        }

        $accounts->restore($user);

        return response()->json(AuthController::signedInPayload($user));
    }

    public function export(Request $request, AccountDeletionService $accounts): StreamedResponse
    {
        $user = $request->user();
        $data = $accounts->export($user);
        $name = 'medicon-data-'.now()->format('Y-m-d').'.json';

        return response()->streamDownload(
            fn () => print(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            $name,
            ['Content-Type' => 'application/json'],
        );
    }

    /** A one-time token, kept only as a hash on the server. */
    public static function issueRestoreToken(User $user): string
    {
        $token = Str::random(64);
        Cache::put(self::restoreKey($token), $user->id, now()->addMinutes(self::RESTORE_TTL_MINUTES));

        return $token;
    }

    private static function restoreKey(string $token): string
    {
        return 'account_restore:'.hash('sha256', $token);
    }

    private function refuse(User $user): ?JsonResponse
    {
        if ($user->hasRole('admin')) {
            return response()->json(['message' => 'Admin accounts are removed on the server, not in the app.'], 403);
        }
        if (! $user->phone) {
            return response()->json(['message' => 'Add a phone number to your account first; deleting needs a code sent to it.'], 422);
        }

        return null;
    }
}
