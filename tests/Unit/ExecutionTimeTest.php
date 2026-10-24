<?php

use App\Support\ExecutionTime;

// Audit E8: the AI chats raise PHP's web time limit, never lower it.

afterEach(fn () => set_time_limit(0));

it('raises a shorter limit', function () {
    set_time_limit(30);
    ExecutionTime::atLeast(85);
    expect((int) ini_get('max_execution_time'))->toBe(85);
});

it('keeps a longer limit', function () {
    set_time_limit(120);
    ExecutionTime::atLeast(85);
    expect((int) ini_get('max_execution_time'))->toBe(120);
});

it('adds no limit where there is none', function () {
    set_time_limit(0);
    ExecutionTime::atLeast(85);
    expect((int) ini_get('max_execution_time'))->toBe(0);
});
