<?php

declare(strict_types=1);

use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Exceptions\InvalidDomainValueException;
use GigEconomics\Support\EnergyLogImporter;

it('parses a quoted pipe-delimited export with a BOM', function (): void {
    $psv = "\xEF\xBB\xBF".'"date"|"time"|"energy"|"unit_price"|"odometer"'."\r\n"
        .'"2026-04-05"|"04:57:00"|"8.158"|"2.8783"|"44619"'."\r\n"
        .'"2026-04-08"|"20:54:00"|"7.187"|"2.9035"|"44950"';

    $log = (new EnergyLogImporter)->fromString($psv);

    expect($log->count())->toBe(2);
});

it('parses a vendor name that contains a comma', function (): void {
    $psv = '"date"|"energy"|"unit_price"|"vendor_name"'."\n"
        .'"2026-04-05"|"8.158"|"2.8783"|"E/S LLANO, GRANDE (PETROECUADOR)"';

    $log = (new EnergyLogImporter)->fromString($psv);

    expect($log->all()[0]->vendorName)->toBe('E/S LLANO, GRANDE (PETROECUADOR)');
});

it('skips rows that only reserve capacity', function (): void {
    $psv = '"date"|"energy"|"unit_price"|"odometer"'."\n"
        .'"2026-04-05"|"8.158"|"2.8783"|"44619"'."\n"
        .'|||'."\n"
        .'||| ';

    $log = (new EnergyLogImporter)->fromString($psv);

    expect($log->count())->toBe(1);
});

it('recomputes totals instead of trusting exported calculated columns', function (): void {
    // The export carries a deliberately wrong total; the importer must ignore it.
    $psv = '"date"|"energy"|"unit_price"|"total"'."\n"
        .'"2026-04-05"|"8.158"|"2.8783"|"999999"';

    $log = (new EnergyLogImporter)->fromString($psv);
    $purchase = $log->all()[0];

    expect(round($purchase->total()->toFloat(), 4))->toBe(27.0033);
});

it('imports an electric charging log priced per kilowatt-hour', function (): void {
    $psv = '"date"|"energy"|"unit_price"|"odometer"|"vendor_name"'."\n"
        .'"2026-05-01"|"40"|"0.25"|"1000"|"Supercharger A"'."\n"
        .'"2026-05-03"|"40"|"0.25"|"1240"|"Supercharger A"';

    $log = (new EnergyLogImporter)->fromString($psv, 0.0, EnergyType::Electric);

    expect($log->count())->toBe(2)
        ->and($log->isElectric())->toBeTrue()
        ->and(round($log->all()[0]->total()->toFloat(), 2))->toBe(10.00)
        ->and($log->economies()[0]->toFloat())->toBe(6.0);
});

it('reads an explicit energy_type column when present', function (): void {
    $psv = '"date"|"energy"|"unit_price"|"energy_type"'."\n"
        .'"2026-05-01"|"40"|"0.25"|"electric"';

    $log = (new EnergyLogImporter)->fromString($psv);

    expect($log->all()[0]->isElectric())->toBeTrue();
});

it('throws when the file does not exist', function (): void {
    (new EnergyLogImporter)->fromFile('/path/that/does/not/exist.psv');
})->throws(InvalidDomainValueException::class);
