<?php

/*
 * One racer for tests/Feature/BookingRaceTest.php (task B3).
 *
 * Boots the app in its own PHP process (so it has its own MySQL connection),
 * waits until a shared start time, sends one API request and prints
 * {"status": <http status>, "startedAt": <microtime>} as JSON.
 *
 * Usage: php race-request.php <METHOD> <path> <token> <json body> <start at (unix float)>
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

[, $method, $path, $token, $body, $startAt] = $argv + array_fill(0, 6, null);

if (getenv('APP_ENV') !== 'testing' || getenv('MEDICON_RACE_TEST') !== '1' || ! getenv('DB_DATABASE')) {
    fwrite(STDERR, "race-request.php only runs from BookingRaceTest.\n");
    exit(2);
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// Never touch a database other than the one the test passed in.
if (config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== getenv('DB_DATABASE')) {
    fwrite(STDERR, "race-request.php: wrong database, refusing to run.\n");
    exit(2);
}

// Connect before the start line so every racer only has the request left to do.
DB::connection()->getPdo();

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

$startedAt = microtime(true);

$request = Request::create($path, $method, [], [], [], [
    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    'HTTP_ACCEPT' => 'application/json',
    'CONTENT_TYPE' => 'application/json',
], $body);

$response = $kernel->handle($request);

echo json_encode(['status' => $response->getStatusCode(), 'startedAt' => $startedAt]);

$kernel->terminate($request, $response);
