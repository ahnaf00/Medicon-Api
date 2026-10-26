<?php

use App\Models\Appointment;
use App\Models\User;
use App\Services\DoctorSlotService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/*
 * Task B3: several people take the same slot at the same moment.
 *
 * Each request runs in its own PHP process with its own MySQL connection, so
 * the row locks in AppointmentController are really tested. Normal tests can't
 * do this: they run inside one transaction on one connection.
 *
 * Runs only when the suite is pointed at a MySQL database whose name ends in
 * "_test" (skipped on the default SQLite run):
 *   DB_CONNECTION=mysql DB_DATABASE=medicon_test DB_USERNAME=root DB_PASSWORD= php artisan test --group=mysql
 * The racers' data has to be committed, so this file empties the test
 * database's tables before and after each test.
 */

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Needs MySQL: run the suite with DB_CONNECTION=mysql DB_DATABASE=medicon_test.');
    }

    if (! str_ends_with(DB::getDatabaseName(), '_test')) {
        $this->fail('BookingRaceTest empties tables, so the database name must end in "_test".');
    }

    if (! RefreshDatabaseState::$migrated) {
        $this->artisan('migrate:fresh');
        RefreshDatabaseState::$migrated = true;
    } else {
        raceEmptyTables();
    }

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->doctor = makeDoctor();
});

afterEach(function () {
    // Leave the tables empty for the tests that run after this file.
    if (DB::getDriverName() === 'mysql' && str_ends_with(DB::getDatabaseName(), '_test')) {
        raceEmptyTables();
    }
});

function raceEmptyTables(): void
{
    $database = DB::getDatabaseName();

    Schema::withoutForeignKeyConstraints(function () use ($database) {
        // Name the schema: without it MySQL lists every database on the server.
        foreach (Schema::getTableListing($database, false) as $table) {
            if ($table !== 'migrations') {
                DB::table($table)->truncate();
            }
        }
    });
}

/**
 * The first $count free slots on one day within the next 10 days, as UTC ISO strings.
 *
 * @return array<int, string>
 */
function raceFreeSlots(int $doctorId, int $count): array
{
    $slots = app(DoctorSlotService::class);
    $zone = DoctorSlotService::timezone();

    for ($day = 1; $day <= 10; $day++) {
        $date = now($zone)->addDays($day)->toDateString();
        $free = collect($slots->slotsOn($doctorId, $date))
            ->where('available', true)
            ->pluck('time')
            ->take($count);

        if ($free->count() === $count) {
            return $free
                ->map(fn ($time) => Carbon::parse("{$date} {$time}", $zone)->utc()->toIso8601String())
                ->all();
        }
    }

    throw new RuntimeException('No day in the next 10 days has enough free slots.');
}

/**
 * Starts one process per request, lets them all go at the same moment and
 * returns each one's HTTP status, in request order.
 *
 * @param  array<int, array{0: string, 1: string, 2: string, 3: array<string, mixed>}>  $requests
 * @return array<int, int>
 */
function raceRun(array $requests): array
{
    $db = config('database.connections.mysql');
    $env = [
        'APP_ENV' => 'testing',
        'MEDICON_RACE_TEST' => '1',
        'DB_CONNECTION' => 'mysql',
        'DB_URL' => '',
        'DB_HOST' => (string) $db['host'],
        'DB_PORT' => (string) $db['port'],
        'DB_DATABASE' => (string) $db['database'],
        'DB_USERNAME' => (string) $db['username'],
        'DB_PASSWORD' => (string) $db['password'],
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
        'MAIL_MAILER' => 'array',
        'BROADCAST_CONNECTION' => 'null',
        'LOG_CHANNEL' => 'null',
        'GEMINI_API_KEY' => '',
    ];

    // Long enough for every process to boot (slow on Windows) before the start line.
    $startAt = microtime(true) + 6;

    $processes = [];
    foreach ($requests as [$method, $path, $token, $body]) {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Support/race-request.php'), $method, $path, $token, json_encode($body), (string) $startAt],
            base_path(),
            $env,
            null,
            60,
        );
        $process->start();
        $processes[] = $process;
    }

    $results = [];
    foreach ($processes as $process) {
        $process->wait();
        $output = $process->getOutput();

        if (! preg_match('/\{"status":\d+,"startedAt":[\d.E+-]+\}/', $output, $match)) {
            throw new RuntimeException("A racer failed:\n".$output."\n".$process->getErrorOutput());
        }

        $results[] = json_decode($match[0], true);
    }

    // If a process booted late, the requests didn't really overlap and the test proves nothing.
    $started = array_column($results, 'startedAt');
    expect(max($started) - min($started))->toBeLessThan(0.5, 'The racers did not start together; raise the start delay.');
    expect(min($started))->toBeGreaterThanOrEqual($startAt);

    return array_column($results, 'status');
}

function raceBook(User $patient, int $doctorId, string $at): array
{
    return ['POST', '/api/v1/appointments', $patient->createToken('race')->plainTextToken, [
        'doctor_user_id' => $doctorId,
        'appointment_datetime' => $at,
        'format' => 'video',
    ]];
}

function activeVisitsAt(int $doctorId, string $at): int
{
    return Appointment::where('doctor_user_id', $doctorId)
        ->where('appointment_datetime', Carbon::parse($at))
        ->whereIn('status', ['scheduled', 'in_progress'])
        ->count();
}

it('gives a slot to exactly one of five patients booking it at once', function () {
    [$slot] = raceFreeSlots($this->doctor->id, 1);
    $patients = collect(range(1, 5))->map(fn () => makePatient());

    $statuses = raceRun($patients->map(fn ($p) => raceBook($p, $this->doctor->id, $slot))->all());

    expect(collect($statuses)->countBy()->all())->toEqual([201 => 1, 422 => 4])
        ->and(activeVisitsAt($this->doctor->id, $slot))->toBe(1);
})->group('mysql');

it('books once when one patient sends the same booking three times at once', function () {
    [$slot] = raceFreeSlots($this->doctor->id, 1);
    $patient = makePatient();

    $statuses = raceRun(array_fill(0, 3, raceBook($patient, $this->doctor->id, $slot)));

    expect(collect($statuses)->countBy()->all())->toEqual([201 => 1, 422 => 2])
        ->and(Appointment::where('patient_user_id', $patient->id)->count())->toBe(1);
})->group('mysql');

it('lets only one of a reschedule and a new booking take the same slot', function () {
    [$current, $target] = raceFreeSlots($this->doctor->id, 2);
    $mover = makePatient();
    $booker = makePatient();
    $visit = Appointment::factory()->create([
        'patient_user_id' => $mover->id,
        'doctor_user_id' => $this->doctor->id,
        'appointment_datetime' => Carbon::parse($current),
        'status' => 'scheduled',
        'format' => 'video',
    ]);

    [$moveStatus, $bookStatus] = raceRun([
        ['PATCH', "/api/v1/appointments/{$visit->id}/reschedule", $mover->createToken('race')->plainTextToken,
            ['appointment_datetime' => $target]],
        raceBook($booker, $this->doctor->id, $target),
    ]);

    // Exactly one wins; the loser is told the slot is taken.
    $winners = ($moveStatus === 200 ? 1 : 0) + ($bookStatus === 201 ? 1 : 0);
    expect($winners)->toBe(1)
        ->and([$moveStatus, $bookStatus])->toContain(422)
        ->and(activeVisitsAt($this->doctor->id, $target))->toBe(1);
})->group('mysql');
