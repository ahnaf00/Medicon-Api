<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function checkInteractions(array $names)
{
    return test()->actingAs(makePatient(), 'sanctum')
        ->postJson('/api/v1/medicines/interactions', [
            'medicines' => array_map(fn ($name) => ['name' => $name], $names),
        ]);
}

it('reports an unknown pair as not checked, never as safe', function () {
    $response = checkInteractions(['DrugX', 'DrugY'])->assertOk();

    $response->assertJsonCount(1, 'pairs')
        ->assertJsonPath('pairs.0.pair', ['DrugX', 'DrugY'])
        ->assertJsonPath('pairs.0.checked', false)
        ->assertJsonPath('pairs.0.severity', 'unknown');

    expect($response->json('pairs.0.warning'))->toContain('Not checked');
    expect(strtolower($response->getContent()))->not->toContain('no known interactions');
});

it('flags a known pair as a checked high-severity interaction', function () {
    checkInteractions(['Warfarin 5mg', 'Aspirin'])
        ->assertOk()
        ->assertJsonPath('pairs.0.checked', true)
        ->assertJsonPath('pairs.0.severity', 'high');
});

it('returns one entry per pair, so unchecked pairs are not hidden by a match', function () {
    $response = checkInteractions(['Warfarin', 'Aspirin', 'DrugX'])->assertOk();

    $response->assertJsonCount(3, 'pairs');
    expect(collect($response->json('pairs'))->where('checked', false))->toHaveCount(2);
});

it('accepts more than five medicines', function () {
    checkInteractions(['A1', 'B2', 'C3', 'D4', 'E5', 'F6'])
        ->assertOk()
        ->assertJsonCount(15, 'pairs');
});
