<?php

namespace Tests;

use App\Domain\Mail\Providers\FakeMailProvider;
use App\Domain\Mail\Providers\FakeManagedMailboxProvisioner;
use App\Domain\Mail\Services\FakeDnsResolver;
use App\Domain\Mail\Services\FakeDomainAuthChecker;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FakeMailProvider::reset();
        FakeManagedMailboxProvisioner::reset();
        FakeDomainAuthChecker::reset();
        FakeDnsResolver::reset();

        $this->withoutVite();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
