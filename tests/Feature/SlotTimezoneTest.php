<?php

use App\Models\Appointment;
use App\Models\DoctorAvailability;
use App\Models\DoctorScheduleException;
use App\Services\DoctorSlotService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    // Sunday 2026-10-04 08:00 Dhaka (02:00 UTC).
    $this->travelTo('2026-10-04 02:00:00');
    $this->patient = makePatient();
    $this->doctor = makeDoctor();
});

function tzSlots($doctor, string $date)
{
    return test()->getJson("/api/v1/doctors/{$doctor->id}/slots?date={$date}");
}

function tzBook($datetime, $doctor = null)
{
    return test()->actingAs(test()->patient, 'sanctum')->postJson('/api/v1/appointments', [
        'doctor_user_id' => ($doctor ?? test()->doctor)->id,
        'appointment_datetime' => $datetime,
        'format' => 'video',
    ]);
}

describe('slots', function () {
    it('builds working hours in Dhaka time and returns UTC instants', function () {
        tzSlots($this->doctor, '2026-10-05')
            ->assertOk()
            ->assertJsonPath('slots.0', ['time' => '09:00', 'datetime' => '2026-10-05T03:00:00+00:00', 'available' => true])
            ->assertJsonPath('slots.15.time', '16:30')
            ->assertJsonPath('slots.15.datetime', '2026-10-05T10:30:00+00:00');
    });

    it('puts an early-morning Dhaka slot on the previous UTC day', function () {
        // Monday 00:00–01:00 Dhaka is Sunday 18:00–19:00 UTC.
        DoctorAvailability::create([
            'doctor_user_id' => $this->doctor->id, 'day_of_week' => 1,
            'start_time' => '00:00:00', 'end_time' => '01:00:00', 'is_active' => true,
        ]);
        Appointment::factory()->create([
            'doctor_user_id' => $this->doctor->id, 'patient_user_id' => $this->patient->id,
            'appointment_datetime' => '2026-10-04 18:30:00', 'status' => 'scheduled',
        ]);

        tzSlots($this->doctor, '2026-10-05')
            ->assertOk()
            ->assertJsonCount(2, 'slots')
            ->assertJsonPath('slots.0', ['time' => '00:00', 'datetime' => '2026-10-04T18:00:00+00:00', 'available' => true])
            ->assertJsonPath('slots.1', ['time' => '00:30', 'datetime' => '2026-10-04T18:30:00+00:00', 'available' => false]);
    });

    it('applies exceptions on the Dhaka calendar date', function () {
        DoctorScheduleException::create([
            'doctor_user_id' => $this->doctor->id, 'date' => '2026-10-05', 'time' => '09:00:00', 'type' => 'disabled',
        ]);

        tzSlots($this->doctor, '2026-10-05')->assertJsonPath('slots.0.time', '09:30');
    });

    it('treats today as the Dhaka date when UTC is still on the previous day', function () {
        // 23:30 UTC Sunday is 05:30 Monday in Dhaka.
        $this->travelTo('2026-10-04 23:30:00');

        tzSlots($this->doctor, '2026-10-04')->assertUnprocessable()->assertJsonValidationErrors('date');
        tzSlots($this->doctor, '2026-10-05')->assertOk()->assertJsonCount(16, 'slots');
    });

    it('finds the next free slot from Dhaka time', function () {
        $this->travelTo('2026-10-04 23:30:00'); // Monday 05:30 Dhaka

        $next = app(DoctorSlotService::class)->nextAvailable([$this->doctor->id]);

        expect($next[$this->doctor->id]->toIso8601String())->toBe('2026-10-05T03:00:00+00:00');
    });
});

describe('booking', function () {
    it('stores an offset datetime as UTC and takes the slot', function () {
        tzBook('2026-10-05T09:00:00+06:00')->assertCreated();

        $appointment = Appointment::sole();
        expect($appointment->getRawOriginal('appointment_datetime'))->toBe('2026-10-05 03:00:00');

        tzSlots($this->doctor, '2026-10-05')->assertJsonPath('slots.0.available', false);
    });

    it('accepts the slot datetime exactly as the slots endpoint returns it', function () {
        $datetime = tzSlots($this->doctor, '2026-10-05')->json('slots.2.datetime');

        tzBook($datetime)->assertCreated();
        expect(Appointment::sole()->appointment_datetime->toIso8601String())->toBe('2026-10-05T04:00:00+00:00');
    });

    it('reads a datetime without an offset as Dhaka time', function () {
        tzBook('2026-10-05 09:30:00')->assertCreated();

        expect(Appointment::sole()->getRawOriginal('appointment_datetime'))->toBe('2026-10-05 03:30:00');
    });

    it('rejects a slot that is already booked', function () {
        tzBook('2026-10-05T09:00:00+06:00')->assertCreated();

        tzBook('2026-10-05T03:00:00Z')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('appointment_datetime');
        expect(Appointment::count())->toBe(1);
    });

    it('rejects times that are not slots', function (string $datetime) {
        tzBook($datetime)->assertUnprocessable()->assertJsonValidationErrors('appointment_datetime');
    })->with([
        'off the grid' => '2026-10-05T09:10:00+06:00',
        'after hours (18:00 Dhaka)' => '2026-10-05T12:00:00Z',
        'day off (Friday)' => '2026-10-09T09:00:00+06:00',
        'in the past' => '2026-10-04T07:30:00+06:00',
    ]);

    it('rejects a user who is not a doctor', function () {
        tzBook('2026-10-05T09:00:00+06:00', makePatient())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('appointment_datetime');
    });

    it('lets a cancelled slot be booked again', function () {
        tzBook('2026-10-05T09:00:00+06:00')->assertCreated();
        Appointment::sole()->update(['status' => 'cancelled']);

        tzBook('2026-10-05T09:00:00+06:00')->assertCreated();
    });
});
