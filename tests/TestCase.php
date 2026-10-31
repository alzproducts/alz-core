<?php

declare(strict_types=1);

namespace Tests;

use DG\BypassFinals;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Override;

abstract class TestCase extends BaseTestCase
{
    #[Override]
    protected function setUp(): void
    {
        // intentional: PHPStan RuleTestCase (BetterReflection FileReadTrapStreamWrapper) restores the native
        // file:// wrapper, silently disabling BypassFinals for the rest of the worker; enable() re-registers it.
        BypassFinals::enable();
        parent::setUp();
        Http::preventStrayRequests();
    }
}
