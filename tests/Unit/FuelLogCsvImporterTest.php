<?php

declare(strict_types=1);

use GigEconomics\Exceptions\InvalidDomainValueException;
use GigEconomics\Support\FuelLogCsvImporter;

it('parses a doubled-quote spreadsheet export with a BOM', function (): void {
    $csv = "\xEF\xBB\xBF".'"""Fecha"",""Hora"",""Galones"",""Valor Unitario"",""Kilometraje"""'."\r\n"
        .'"""2026-04-05"",""04:57:00"",""8.158"",""2.8783"",""44619"""'."\r\n"
        .'"""2026-04-08"",""20:54:00"",""7.187"",""2.9035"",""44950"""';

    $log = (new FuelLogCsvImporter)->fromString($csv);

    expect($log->count())->toBe(2);
});

it('skips rows that only reserve capacity', function (): void {
    $csv = 'Fecha,Galones,Valor Unitario,Kilometraje'."\n"
        .'2026-04-05,8.158,2.8783,44619'."\n"
        .',,,'."\n"
        .',,,';

    $log = (new FuelLogCsvImporter)->fromString($csv);

    expect($log->count())->toBe(1);
});

it('recomputes totals instead of trusting spreadsheet calculated columns', function (): void {
    // The CSV carries a deliberately wrong Monto; the importer must ignore it.
    $csv = 'Fecha,Galones,Valor Unitario,Monto'."\n"
        .'2026-04-05,8.158,2.8783,999999';

    $log = (new FuelLogCsvImporter)->fromString($csv);
    $purchase = $log->all()[0];

    expect(round($purchase->total()->toFloat(), 4))->toBe(27.0033);
});

it('throws when the file does not exist', function (): void {
    (new FuelLogCsvImporter)->fromFile('/path/that/does/not/exist.csv');
})->throws(InvalidDomainValueException::class);
