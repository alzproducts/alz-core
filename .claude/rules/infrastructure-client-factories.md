---
paths:
  - "app/Infrastructure/**/*ClientFactory.php"
---

# Infrastructure — Client Factory Rules

## Configuration Validation

- DO throw `App\Domain\Exceptions\InvalidConfigurationException($envVar)` when reading a required Laravel config value that is missing, empty, or of the wrong type. **Why:** config gaps are bootstrap-time programming mistakes, not runtime conditions; using a domain exception keeps them grouped with other configuration failures for monitoring.
- DO validate config up-front in the factory and pass a typed config value object to the transport — the transport should never read config directly.

Canonical: `LinnworksClientFactory::requireStringConfig()`, `LinnworksClientFactory::createConfig()`.
