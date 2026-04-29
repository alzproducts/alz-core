# Plan: Contact Form Price — Money Value Object

**Issue:** #672
**Date:** 2026-04-29

## Problem

`SelectedProduct::price` is typed `?string` and the raw frontend payload string (e.g. `"12.345678"`) flows unmodified through Domain → transformer → Helpscout email body, where it renders with many decimal places. No tax-type information is captured on the form payload, and the price is the net (excl VAT) storefront price.

## Decisions Made

| Decision | Choice | Rationale |
|---|---|---|
| Conversion site | `ContactSubmissionMapper::mapProduct()` | Earliest layer-safe boundary; DTO stays raw transport, Domain holds Money |
| Tax type | `Money::exclusive()` | Price is net (excl VAT); no VAT status captured on form; label reflects this |
| Factory for mapper | `Money::exclusiveFromString($string)` (new) | Mirrors `inclusiveFromString()`; preserves float precision without early rounding |
| Email output | `$money->formatNet()` (new helper) | Enforces 2dp with trailing zeros; reusable |
| Email label | `Price (excl VAT):` | Honest — support agent knows they're seeing net price |
| JSONB precision | Lossless on significant digits; trailing zeros may drop | Float round-trip: `"12.345678"` → `12.345678` → `"12.345678"`; `"12.300000"` → `"12.3"` — acceptable |
| JSONB format | Numeric string via `(string) $money->toNet()` | Backward-compatible with existing rows; no migration needed |

## Files to Change

### `app/Domain/Shared/Money/ValueObjects/Money.php`

Add three new methods:

```php
public static function exclusiveFromString(string $amount, string $currency = 'GBP'): self
{
    Assert::numeric($amount, 'Money amount string must be numeric');
    return new self((float) $amount, TaxType::Exclusive, $currency);
}

public function formatNet(int $decimals = 2, string $decimalSep = '.', string $thousandsSep = ''): string
{
    return number_format($this->toNet(), $decimals, $decimalSep, $thousandsSep);
}

public function formatGross(int $decimals = 2, string $decimalSep = '.', string $thousandsSep = ''): string
{
    return number_format($this->toGross(), $decimals, $decimalSep, $thousandsSep);
}
```

### `app/Domain/ContactSubmission/ValueObjects/SelectedProduct.php`

- Change `public ?string $price` → `public ?Money $price`
- Add `use App\Domain\Shared\Money\ValueObjects\Money;`
- `toArray()`: `'price' => $this->price !== null ? (string) $this->price->toNet() : null`
- `fromArray()`: `price: isset($data['price']) ? Money::exclusiveFromString((string) $data['price']) : null`
- Update `@param` array shape on `fromArray()`: `price?: string|null`

### `app/Presentation/Http/ContactForm/Mappers/ContactSubmissionMapper.php`

In `mapProduct()` (line 121):
```php
price: $data->product->price !== null
    ? Money::exclusiveFromString($data->product->price)
    : null,
```
Add `use App\Domain\Shared\Money\ValueObjects\Money;`

### `app/Application/ContactSubmission/Transformers/ContactSubmissionToConversationCommandTransformer.php`

Line 108, change:
```php
$product->price !== null ? '<strong>Price:</strong> ' . self::e($product->price) : null,
```
to:
```php
$product->price !== null ? '<strong>Price (excl VAT):</strong> ' . self::e($product->price->formatNet()) : null,
```

### Tests

- `tests/Unit/Domain/ContactSubmission/ValueObjects/SelectedProductTest.php` — construct with `Money::exclusive(12.34)`, assert `->price->toNet() === 12.34`, verify `toArray()` output string, `fromArray()` round-trip
- `tests/Unit/Application/ContactSubmission/Transformers/ContactSubmissionToConversationCommandTransformerTest.php` — assert email body contains `Price (excl VAT): 12.34` not raw string
- `tests/Unit/Domain/Shared/Money/ValueObjects/MoneyTest.php` — add cases for `exclusiveFromString`, `formatNet`, `formatGross` with 0dp/2dp/trailing zeros

## Out of Scope

- Refactoring existing `number_format($price->toGross(), 2)` in `UpdatePriceCommand.php:42` — natural follow-up once helpers exist
- Backfilling JSONB rows — shape is backward-compatible; existing rows read fine via `exclusiveFromString`
- Adding VAT status to the form payload — separate concern; this plan works without it
