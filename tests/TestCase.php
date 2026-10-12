<?php

declare(strict_types=1);

namespace GigEconomics\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Absolute path to a CSV fixture.
     */
    protected function fixture(string $name): string
    {
        return __DIR__.'/Fixtures/'.$name;
    }
}
