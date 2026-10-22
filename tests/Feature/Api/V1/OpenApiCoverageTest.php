<?php

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

/**
 * The iOS client is generated from docs/api/openapi.yaml, so every route it
 * relies on must be documented with the right method.
 */
it('documents every route the iOS app uses in the OpenAPI spec', function () {
    $spec = Yaml::parseFile(base_path('docs/api/openapi.yaml'));
    $documented = collect($spec['paths'])
        ->flatMap(fn (array $operations, string $path) => collect(array_keys($operations))
            ->map(fn (string $method) => strtoupper($method).' '.$path))
        ->all();

    $prefixes = ['api.v1.server', 'api.v1.auth.', 'api.v1.me', 'api.v1.devices.', 'api.v1.threads.', 'api.v1.messages.', 'api.v1.inbox.', 'api.v1.mailboxes.'];

    $operations = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route) => collect($prefixes)->contains(fn (string $prefix) => str_starts_with((string) $route->getName(), $prefix)))
        ->flatMap(fn (RoutingRoute $route) => collect($route->methods())
            ->reject(fn (string $method) => $method === 'HEAD')
            ->map(fn (string $method) => $method.' /'.ltrim(substr($route->uri(), strlen('api/v1')), '/')));

    $missing = $operations
        ->reject(fn (string $operation) => in_array($operation, $documented, true))
        ->values()
        ->all();

    expect($operations->count())->toBeGreaterThanOrEqual(20)
        ->and($operations)->toContain('GET /server', 'POST /auth/token', 'GET /threads/changes', 'PUT /devices/current/push')
        ->and($missing)->toBe([]);
});

it('resolves every component reference in the OpenAPI spec', function () {
    $raw = file_get_contents(base_path('docs/api/openapi.yaml'));
    $spec = Yaml::parse($raw);
    preg_match_all("~\\\$ref: '#/components/(\\w+)/(\\w+)'~", $raw, $matches, PREG_SET_ORDER);

    $unresolved = collect($matches)
        ->reject(fn (array $match) => isset($spec['components'][$match[1]][$match[2]]))
        ->map(fn (array $match) => $match[1].'/'.$match[2])
        ->unique()
        ->values()
        ->all();

    expect($matches)->not->toBeEmpty()
        ->and($unresolved)->toBe([]);
});
