<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// Audit P11: user text is escaped for openFDA, and local names map to US names.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Http::preventStrayRequests();
    $this->patient = makePatient();
});

function fdaLabel(string $brand, string $generic): array
{
    return ['results' => [['openfda' => ['brand_name' => [$brand], 'generic_name' => [$generic]], 'purpose' => ['Pain reliever']]]];
}

function searchMedicine($user, string $q)
{
    return test()->actingAs($user, 'sanctum')->getJson('/api/v1/medicines/search?q='.urlencode($q));
}

it('removes quotes and backslashes from the openFDA phrase', function () {
    Http::fake(['api.fda.gov/*' => Http::response(fdaLabel('Advil', 'IBUPROFEN'))]);

    searchMedicine($this->patient, 'ibu"profen'.chr(92))->assertOk();

    Http::assertSent(fn (HttpRequest $r) => $r->data()['search'] === 'openfda.brand_name:"ibu profen" OR openfda.generic_name:"ibu profen"');
});

it('searches the US name for paracetamol and says so', function () {
    Http::fake(['api.fda.gov/*' => Http::response(fdaLabel('Tylenol', 'ACETAMINOPHEN'))]);

    searchMedicine($this->patient, 'Paracetamol')
        ->assertOk()
        ->assertJsonPath('results.0.searchedAs', 'acetaminophen');

    Http::assertSent(fn (HttpRequest $r) => str_contains($r->data()['search'], 'generic_name:"acetaminophen"'));
});

it('maps a common Bangladeshi brand to its generic', function () {
    Http::fake(['api.fda.gov/*' => Http::response(fdaLabel('Prilosec', 'OMEPRAZOLE'))]);

    searchMedicine($this->patient, 'seclo')->assertOk()->assertJsonPath('results.0.searchedAs', 'omeprazole');
});

it('leaves searchedAs empty when the typed name was searched', function () {
    Http::fake(['api.fda.gov/*' => Http::response(fdaLabel('Advil', 'IBUPROFEN'))]);

    searchMedicine($this->patient, 'ibuprofen')->assertOk()->assertJsonPath('results.0.searchedAs', null);
});

it('does not call openFDA when only punctuation is left', function () {
    Http::fake();

    searchMedicine($this->patient, '""""')->assertOk()->assertJsonPath('results', []);

    Http::assertNothingSent();
});
