<?php

use App\Models\Hospital;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Task H3 part 1 (audit DB-003): the doctor and hospital lists come in pages.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->patient = makePatient();
});

function namedDoctor(string $name, array $profile = []): User
{
    $doctor = makeDoctor();
    $doctor->update(['name' => $name]);
    $doctor->doctorProfile->update($profile);

    return $doctor;
}

describe('doctors', function () {
    it('sends 20 verified doctors per page, A-Z, with page info', function () {
        foreach (range(1, 25) as $n) {
            namedDoctor(sprintf('Dr. %02d', $n));
        }
        makeDoctor('pending');

        $first = $this->getJson('/api/v1/doctors')->assertOk();
        expect($first->json('data'))->toHaveCount(20)
            ->and($first->json('data.0.name'))->toBe('Dr. 01')
            ->and($first->json('meta.total'))->toBe(25)
            ->and($first->json('meta.last_page'))->toBe(2);

        $second = $this->getJson('/api/v1/doctors?page=2')->assertOk();
        expect(collect($second->json('data'))->pluck('name')->all())
            ->toBe(['Dr. 21', 'Dr. 22', 'Dr. 23', 'Dr. 24', 'Dr. 25']);
    });

    it('takes a page size up to 50 and refuses more', function () {
        foreach (range(1, 3) as $n) {
            namedDoctor("Dr. {$n}");
        }

        expect($this->getJson('/api/v1/doctors?per_page=2')->json('data'))->toHaveCount(2);
        $this->getJson('/api/v1/doctors?per_page=51')->assertStatus(422);
        $this->getJson('/api/v1/doctors?per_page=0')->assertStatus(422);
    });

    it('filters to doctors online right now, using the same rule as presence', function () {
        namedDoctor('Dr. Online', ['is_online' => true, 'last_seen_at' => now()->subMinute()]);
        namedDoctor('Dr. Stale', ['is_online' => true, 'last_seen_at' => now()->subMinutes(10)]);
        namedDoctor('Dr. Off', ['is_online' => false, 'last_seen_at' => now()]);

        $names = collect($this->getJson('/api/v1/doctors?online=1')->assertOk()->json('data'))->pluck('name');

        expect($names->all())->toBe(['Dr. Online'])
            ->and($this->getJson('/api/v1/doctors')->json('meta.total'))->toBe(3);
    });

    it('still filters by specialty', function () {
        namedDoctor('Dr. Heart', ['specialty' => 'Cardiology']);
        namedDoctor('Dr. Skin', ['specialty' => 'Dermatology']);

        $names = collect($this->getJson('/api/v1/doctors?specialty=Dermatology')->json('data'))->pluck('name');

        expect($names->all())->toBe(['Dr. Skin']);
    });
});

describe('hospitals', function () {
    it('sends 20 visible hospitals per page, A-Z, and searches across all pages', function () {
        foreach (range(1, 22) as $n) {
            Hospital::factory()->create(['name' => sprintf('Hospital %02d', $n), 'address' => 'Dhaka']);
        }
        Hospital::factory()->create(['name' => 'Hospital 00 hidden', 'is_active' => false]);

        $first = $this->actingAs($this->patient, 'sanctum')->getJson('/api/v1/hospitals')->assertOk();
        expect($first->json('data'))->toHaveCount(20)
            ->and($first->json('data.0.name'))->toBe('Hospital 01')
            ->and($first->json('meta.total'))->toBe(22);

        $search = $this->getJson('/api/v1/hospitals?search=Hospital 22')->assertOk();
        expect(collect($search->json('data'))->pluck('name')->all())->toBe(['Hospital 22']);
    });

    it('shows one visible hospital and hides a hidden one', function () {
        $visible = Hospital::factory()->create(['name' => 'Visible Hospital']);
        $hidden = Hospital::factory()->create(['is_active' => false]);

        $this->getJson("/api/v1/hospitals/{$visible->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Visible Hospital');
        $this->getJson("/api/v1/hospitals/{$hidden->id}")->assertNotFound();
        $this->getJson('/api/v1/hospitals/999999')->assertNotFound();
    });
});
