<?php

use App\Domain\Api\IdempotencyStore;
use Illuminate\Support\Facades\Cache;

it('stores and replays a json response for the same token, route, and key', function () {
    $store = new IdempotencyStore(Cache::store());
    $response = response()->json(['enrolled' => 1], 201);

    $store->put(12, 'api.v1.campaigns.enrollments.store', 'enrol-once', 'request-fingerprint', $response);

    $stored = $store->get(12, 'api.v1.campaigns.enrollments.store', 'enrol-once');

    expect($stored)->not->toBeNull()
        ->and($stored['status'])->toBe(201)
        ->and($stored['body'])->toBe(['enrolled' => 1])
        ->and($stored['fingerprint'])->toBe('request-fingerprint');

    $replay = $store->replay($stored);
    expect($replay->getStatusCode())->toBe(201)
        ->and($replay->getData(true))->toBe(['enrolled' => 1]);
});

it('does not replay a key from a different token', function () {
    $store = new IdempotencyStore(Cache::store());
    $store->put(12, 'api.v1.campaigns.launch', 'launch-1', 'request-fingerprint', response()->json(['ok' => true]));

    expect($store->get(99, 'api.v1.campaigns.launch', 'launch-1'))->toBeNull();
});

it('uses one atomic lock for the same token route and key', function () {
    $store = new IdempotencyStore(Cache::store());
    $first = $store->lock(12, 'api.v1.campaigns.launch', 'launch-once');
    $second = $store->lock(12, 'api.v1.campaigns.launch', 'launch-once');

    expect($first->get())->toBeTrue()
        ->and($second->get())->toBeFalse();

    $first->release();

    expect($second->get())->toBeTrue();

    $second->release();
});

it('keeps the lock lease longer than the maximum waiter', function () {
    expect(IdempotencyStore::LOCK_LEASE_SECONDS)
        ->toBeGreaterThan(IdempotencyStore::LOCK_WAIT_SECONDS);
});
