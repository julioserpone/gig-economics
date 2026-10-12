<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Quality;

/**
 * How much a finding should worry the driver.
 */
enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
