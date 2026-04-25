# Providers Layer

## Configuration Binding Rules

**Fail-fast**: All `.env` values resolved in providers MUST throw `InvalidConfigurationException` when missing or invalid. Never return `null` for values that should be set in `.env`.

```php
// CORRECT: fail-fast
->give(static function (): int {
    $value = \config('shopwired.standard_sign_product_id');
    if (! \is_numeric($value)) {
        throw new InvalidConfigurationException('shopwired.standard_sign_product_id', '...');
    }
    return (int) $value;
});

// WRONG: silently returns null
->give(static fn() => \is_numeric($v = \config('key')) ? (int) $v : null);
```

**Rationale**: Every `.env` variable we reference must be set in all environments (local, CI, production). Returning null defers failure to runtime, making bugs harder to trace.

## Exceptions Used

| Exception | When |
|-----------|------|
| `InvalidConfigurationException` | Missing/invalid `.env` values (extends `LogicException` - unchecked) |
| `RuntimeException` | Runtime contract violations (e.g., RLS context not set) |
| `LogicException` | Programming errors (e.g., missing middleware) |
