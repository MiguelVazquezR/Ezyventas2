<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the "Unit" testsuite of phpunit.xml alive: without this directory
 * `php artisan test` aborts with "Test directory tests/Unit not found".
 */
class ExampleTest extends TestCase
{
    #[Test]
    public function it_runs_the_unit_test_suite(): void
    {
        $this->assertTrue(true);
    }
}
