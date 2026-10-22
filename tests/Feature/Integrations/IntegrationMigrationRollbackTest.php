<?php

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

it('restores SQLite foreign-key checks when integration rollback fails', function () {
    $foreignKeysDisabled = false;
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('sqlite');

    Schema::shouldReceive('getConnection')->once()->andReturn($connection);
    Schema::shouldReceive('disableForeignKeyConstraints')
        ->once()
        ->ordered()
        ->andReturnUsing(function () use (&$foreignKeysDisabled): void {
            $foreignKeysDisabled = true;
        });
    Schema::shouldReceive('dropIfExists')
        ->once()
        ->with('external_usage_entries')
        ->ordered();
    Schema::shouldReceive('dropIfExists')
        ->once()
        ->with('external_operation_attempts')
        ->ordered()
        ->andThrow(new RuntimeException('simulated rollback failure'));
    Schema::shouldReceive('enableForeignKeyConstraints')
        ->once()
        ->ordered()
        ->andReturnUsing(function () use (&$foreignKeysDisabled): void {
            $foreignKeysDisabled = false;
        });

    $migration = require base_path('database/migrations/2026_09_03_110000_create_integration_foundation_tables.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and($foreignKeysDisabled)->toBeFalse();
});
