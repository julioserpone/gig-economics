<?php

declare(strict_types=1);

use GigEconomics\Domain\Money\Currency;
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Money\Percentage;
use GigEconomics\Exceptions\CurrencyMismatchException;
use GigEconomics\Exceptions\InvalidDomainValueException;

it('adds amounts without floating point drift', function (): void {
    $result = Money::of('0.1')->plus(Money::of('0.2'));

    expect((string) $result)->toBe('0.3')
        ->and($result->equals(Money::of('0.3')))->toBeTrue();
});

it('reproduces the invoice subtotal, tax and total of a workbook row', function (): void {
    // Row 2026-04-05: 8.158 gal @ 2.8783 = 23.4811714 subtotal; 15% IVA; total 27.00334711
    $subtotal = Money::of('8.158')->multipliedBy('2.8783', 7);
    $tax = $subtotal->percentage(Percentage::of(15));
    $total = $subtotal->plus($tax);

    expect($subtotal->format(7, false))->toBe('23.4811714')
        ->and($tax->format(7, false))->toBe('3.5221757')
        ->and($total->format(8, false))->toBe('27.00334711');
});

it('returns the weighted ratio of sums and never an average of ratios', function (): void {
    $spend = Money::of('1912.3488529');
    $volume = Money::of('395.24');

    expect($spend->divideBy($volume, 6)->__toString())->toBe('4.838450');
});

it('guards against dividing by zero', function (): void {
    Money::of('10')->divideBy(Money::zero());
})->throws(InvalidDomainValueException::class);

it('refuses to combine different currencies', function (): void {
    Money::of('10', Currency::usd())->plus(Money::of('10', Currency::of('EUR', '€')));
})->throws(CurrencyMismatchException::class);

it('rounds for presentation without mutating the stored amount', function (): void {
    $amount = Money::of('27.00334711');
    $rounded = $amount->roundedTo(2);

    expect($rounded->format(2, false))->toBe('27.00')
        ->and((string) $amount)->toBe('27.00334711');
});

it('sums a list of amounts', function (): void {
    $total = Money::sum([Money::of('10.50'), Money::of('20.25'), Money::of('0.25')]);

    expect($total->format(2, false))->toBe('31.00');
});

it('exposes threshold comparisons used by rankings', function (): void {
    expect(Money::of('5')->isGreaterThan(Money::of('4')))->toBeTrue()
        ->and(Money::of('5')->isLessThan(Money::of('4')))->toBeFalse()
        ->and(Money::of('5')->max(Money::of('9'))->format(0, false))->toBe('9');
});

it('converts percentages between fraction and points', function (): void {
    $rate = Percentage::of(15);

    expect($rate->asFraction()->toScale(2)->__toString())->toBe('0.15')
        ->and($rate->asPercentagePoints()->__toString())->toBe('15.000000');
});
