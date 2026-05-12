# COR-126 / GitHub #744 — Skip incomplete ShopWired order webhooks missing reference

## Status: In Progress — branch created, changes stashed, PR pending

## What's Done

- Diagnosed Sentry issue ALZ-CORE-AW: `CannotCreateData` in `ShopwiredOrderWebhookParser::parseOrder()` when ShopWired webhook payload for certain order types omits 5 fields (`reference`, `archived`, `paymentMethod`, `lineItemVatCalculation`, `status`)
- 9 occurrences on 2026-05-12, ~every 8-16 min — ShopWired retrying after 500 responses
- Order 11635128 confirmed present in `shopwired.orders` (arrived via batch sync)
- Implemented Option E (split responsibility):
  1. `OrderWebhookParserInterface::parseOrder()` → return type `Order` → `?Order`
  2. `ShopwiredOrderWebhookParser::parseOrder()` → returns `null` when `reference` missing
  3. `HandleOrderWebhookService` → extracted `handleOrderSync()` private method; logs info-level skip via injected `LoggerInterface` (PSR-3, not facade — required by Deptrac)
  4. `phpstan-complexity-baseline.neon` → updated line count 36→33 for `execute()`
- All linters pass, 3381 tests pass
- Linear issue COR-126 created (bug label)
- Branch: `bugfix/cor-126-skip-incomplete-shopwired-order-webhooks-missing-reference` from `origin/develop`
- Changes are STASHED on the new branch — need `git stash pop` then commit + PR

## What's Next

1. `git stash pop` to restore changes on new branch
2. Commit with message referencing COR-126 / Fixes ALZ-CORE-AW
3. Run `/pr` skill to create PR → develop
4. Resolve Sentry issue ALZ-CORE-AW after PR merged

## Key Decisions

- **Option E chosen** (not parser-only, not service-only): parser validates completeness (`reference` presence), service decides what "incomplete" means for business (skip + log)
- **`reference` as the guard field**: user confirmed it should always be set; missing it identifies the "rare order type" that triggers this bug
- **PSR-3 LoggerInterface** injected in constructor (not `Log` facade) — required by Deptrac/PHPArkitect Application layer rules

## Files Changed

- `app/Application/Contracts/Shopwired/OrderWebhookParserInterface.php`
- `app/Application/Shopwired/Services/HandleOrderWebhookService.php`
- `app/Infrastructure/Shopwired/Parsers/ShopwiredOrderWebhookParser.php`
- `phpstan-complexity-baseline.neon`
