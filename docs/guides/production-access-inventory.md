# Production Access Inventory

Every route found from the local set-up to production (COR-261), and what was done with it. Outside production each integration binds a recording fake or a fail-closed stub unless its `*_LIVE` opt-in is true ([ADR-0015](../adr/0015-external-integrations-fake-outside-production.md)); a route that stays is human-only and production-only. Disposition is `removed`, `replaced: <by what>` or `production-only: <path>`. Add a row when a new route appears.

---

## env

| Route | Evidence | Disposition |
|---|---|---|
| Vendor credentials in an untracked `.env` make every local call live | `config/integrations.php:19` | replaced: one `*_LIVE` opt-in per integration, blank in `.env.example:86-98` |
| A populated `.env` feeds Pest (only the Sentry DSN was forced) | `phpunit.xml:98` | replaced: every `*_LIVE` forced `false` in `phpunit.xml`, `phpunit-app.xml:48`, `phpunit-domain.xml:45` |
| `.env` DB or Redis host (or `DB_URL`/`REDIS_URL`) pointing at a remote server | `app/Providers/AppServiceProvider.php:113` | replaced: local/testing boot refuses a host outside loopback plus `LOCAL_ALLOWED_HOSTS` (`.env.example:80`) |
| `.env` `SUPABASE_URL` pointing at a hosted project | `app/Providers/AppServiceProvider.php:127` | replaced: same host guard unless `SUPABASE_LIVE`; phpunit uses loopback (`phpunit.xml:97`) |
| Unset `APP_ENV` fell back to `production`, opening every gate | `config/app.php:11` | replaced: boot throws `InvalidConfigurationException` on an unset `APP_ENV` |
| `HS_*` names in `.env.example` that no config read | `.env.example:172` | replaced: `HELPSCOUT_*` names, gated by `HELPSCOUT_LIVE` |
| `AWS_*` credentials select the real S3 bucket | `.env.example:133` | replaced: remote disks resolve to `local` unless production or `REMOTE_STORAGE_LIVE` (`config/filesystems.php:34`) |
| `SENTRY_LARAVEL_DSN` sends local errors to the production project | `.env.example:334` | replaced: DSN is `null` under local/testing unless `SENTRY_LIVE` (`config/sentry.php:17`) |
| `LOG_SLACK_WEBHOOK_URL` posts local logs to Slack | `.env.example:35` | replaced: `slack` log channel becomes the `single` file unless production or `SLACK_LIVE` (`config/logging.php:85`) |
| `MIXPANEL_BASE_URL` vendor endpoint | `.env.example:243` | replaced: `FailClosedMixpanelClient` unless `MIXPANEL_LIVE` (`app/Providers/MixpanelServiceProvider.php:62`) |
| Production env template | `.env.production.example` | production-only: `.env.production.example` |
| Production compose interpolates `SUPABASE_URL` from `.env` | `docker-compose.prod.yml:73` | production-only: `docker-compose.prod.yml` (sets `APP_ENV: production` at `:23`) |

---

## config defaults

| Route | Evidence | Disposition |
|---|---|---|
| `filesystems.remote` defaulted to `s3` (`RemoteStorageInterface`) | `config/filesystems.php:34` | replaced: `local` unless production or `REMOTE_STORAGE_LIVE` |
| `feeds.doofinder.storage_disk` defaulted to `s3` | `config/feeds.php:23` | replaced: `local` unless production or `REMOTE_STORAGE_LIVE` |
| `logging.channels.slack` Monolog curl, outside any binding | `config/logging.php:85` | replaced: `single` file channel unless production or `SLACK_LIVE` |
| `sentry.dsn` read with no environment check | `config/sentry.php:17` | replaced: `null` under local/testing unless `SENTRY_LIVE` |
| `app.env` default `production` | `config/app.php:40` | replaced: required `APP_ENV`, no default (`config/app.php:11`) |
| `bing-ads.environment` default `Production` | `config/bing-ads.php:43` | replaced: `FailClosedBingAdsClient` unless `BING_ADS_LIVE` (`app/Providers/BingAdsServiceProvider.php:78`) |

---

## Make

| Route | Evidence | Disposition |
|---|---|---|
| `sync-from-prod` (ran `dev:sync-from-prod`) | `Makefile` target deleted in this branch | removed |
| `db-reset-full-pii` (reset + production sync with PII) | `Makefile` target deleted in this branch | removed |
| `serve` starts a queue worker that runs live jobs | `Makefile:414` | replaced: workers reach only fakes and fail-closed stubs outside production |

---

## `bin/`

| Route | Evidence | Disposition |
|---|---|---|
| `bin/serve` `queue:listen` drains jobs that call vendors | `bin/serve:28` | replaced: per-integration gates (`config/integrations.php:19`) |
| `docker-entrypoint.sh` defaulted an unset `APP_ENV` to `production` | `docker-entrypoint.sh:40` | replaced: entrypoint refuses to start |

---

## commands/jobs

| Route | Evidence | Disposition |
|---|---|---|
| `dev:sync-from-prod` pulled production data (with `--incl-pii`) | `SyncFromProdCommand.php` deleted in this branch | removed |
| `slack:test` basic mode posts through the raw notification dispatcher | `app/Presentation/Console/Commands/Dev/TestSlackNotificationCommand.php:72` | replaced: gate check first, fails closed without `SLACK_LIVE` |
| `dev:test-price-update` pushes prices to ShopWired | `app/Presentation/Console/Commands/Dev/TestPriceUpdateCommand.php:26` | replaced: `FailClosedShopwiredTransport` (`app/Infrastructure/Shopwired/ShopwiredClientFactory.php:145`) |
| `dev:seed-fake` guard read `host` only, so a remote `DB_URL` passed | `app/Presentation/Console/Commands/Dev/SeedFakeDataCommand.php:49` | replaced: `LocalHostAllowlist` parses `url` first |
| ShopWired clients (17 interfaces, cached static transport) | `app/Infrastructure/Shopwired/ShopwiredClientFactory.php:145` | replaced: `FailClosedShopwiredTransport`, chosen before the static cache |
| Linnworks clients (9 interfaces, cached static transport) | `app/Infrastructure/Linnworks/LinnworksClientFactory.php:177` | replaced: `FailClosedLinnworksTransport`, chosen before the static cache |
| Linnworks connectivity client (session manager) | `app/Providers/LinnworksServiceProvider.php:105` | replaced: `FailClosedConnectivityClient` |
| `LinnworksClientFactory::getAuthToken()` mints a live session | `app/Infrastructure/Linnworks/LinnworksClientFactory.php:160` | replaced: gate checked first, fails closed |
| HelpScout reads and retry lookup (SDK Guzzle token mint) | `app/Providers/HelpScoutServiceProvider.php:71` | replaced: recording fakes (`:71-105`) |
| HelpScout conversation write (contact submissions) | `app/Providers/ContactSubmissionServiceProvider.php:90` | replaced: `RecordingConversationWriteClient` |
| HelpScout system user id | `app/Providers/HelpScoutServiceProvider.php:118` | replaced: placeholder id outside production |
| Slack chat notifications (7 listeners) | `app/Providers/NotificationServiceProvider.php:30` | replaced: `RecordingChatNotification` |
| Google/Bing conversion uploads (concrete adapters) | `app/Providers/ConversionServiceProvider.php:67` | replaced: `RecordingAdPlatformConversionAdapter` per non-live platform |
| Google Ads reads and conversion probe | `app/Providers/GoogleAdsServiceProvider.php:66` | replaced: fail-closed stubs (`:66`, `:93`) |
| Bing Ads client (live SOAP `getAccount()` at resolve) | `app/Providers/BingAdsServiceProvider.php:78` | replaced: `FailClosedBingAdsClient`; the factory is never called |
| Mixpanel client | `app/Providers/MixpanelServiceProvider.php:62` | replaced: `FailClosedMixpanelClient` |
| ClickUp users and tasks clients | `app/Providers/ClickUpServiceProvider.php:104` | replaced: fail-closed stubs (`:104`, `:111`) |
| Reviews.io client (4-hourly schedule) | `app/Providers/ReviewsIoServiceProvider.php:44` | replaced: `FailClosedReviewsIoClient` |
| Doofinder feed processor (`Http::get` source + S3 write) | `app/Providers/ProductSearchFeedServiceProvider.php:50` | replaced: `FailClosedProductSearchFeedProcessor` |
| `FeedController` reads the feed from the S3 disk | `app/Presentation/Http/Controllers/FeedController.php:53` | replaced: feed disk `local` unless production or `REMOTE_STORAGE_LIVE` (`config/feeds.php:23`) |
| Queue failure capture to Sentry | `app/Providers/QueueObservabilityServiceProvider.php:65` | replaced: `null` DSN outside production (`config/sentry.php:17`) |
| Unhandled-exception capture to Sentry | `bootstrap/app.php:74` | replaced: `null` DSN outside production (`config/sentry.php:17`) |
| Supabase JWKS verifier | `app/Providers/AuthServiceProvider.php:47` | replaced: `SUPABASE_URL` must be a local host unless `SUPABASE_LIVE` (`app/Providers/AppServiceProvider.php:127`) |
| `schedule:work` run by hand (schedules register with no environment gate) | `bootstrap/providers.php:29` | replaced: every scheduled vendor call hits a fake or stub above |
| `railway ssh` example in `inventory:bulk-update-cost-prices` | `app/Presentation/Console/Commands/BulkUpdateCostPricesCommand.php:22` | removed |
| `railway ssh` example in `catalog:bulk-update-selling-prices` | `app/Presentation/Console/Commands/BulkUpdateSellingPricesCommand.php:25` | removed |
| `railway ssh` example in `inventory:generate-variant-skus` | `app/Presentation/Console/Commands/GenerateVariantSkusCommand.php` (line deleted in this branch) | removed |
| `railway ssh` guidance in `inventory:update-skus` | `app/Presentation/Console/Commands/UpdateSkusCommand.php:26` | removed |
| `railway ssh` example in `verify:googleads-conversions` | `app/Presentation/Console/Commands/VerifyGoogleAdsConversionCommand.php:23` | removed |

---

## docs

| Route | Evidence | Disposition |
|---|---|---|
| AGENTS.md "Railway CLI" section (`railway ssh` to the production worker) | `AGENTS.md:45` | replaced: "Production Access" section, human-only |
| AGENTS.md escalation pointer "(see Railway / Safety rules)" | `AGENTS.md:66` | replaced: points at "Production Access" |
| AGENTS.md Quick Reference `make db-reset-full-pii` | line deleted in this branch | removed |
| AGENTS.md "Never dispatch to production for smoke tests" | `AGENTS.md:183` | production-only: human, see `AGENTS.md` "Production Access" |
| README "Production data is opt-in" bullet | line deleted in this branch | removed |
| README warning not to run workers with ShopWired/Linnworks credentials | `README.md:230` | replaced: opt-in wording (`README.md:230-231`) |
| README hosting row (Docker to Railway) | `README.md:207` | production-only: `README.md:207` |
| README Known Limitations line on integration tests asserting "rows synced from production systems" | `README.md:283` | replaced: tests assert against the synthetic data `make db-reset-full` seeds |
| database/AGENTS.md "Production data (opt-in)" section | section deleted in this branch | removed |
| tests/AGENTS.md seed note naming `dev:sync-from-prod` data | `tests/AGENTS.md:72` | replaced: synthetic seed only |
| `CustomFieldFactory` resolution text naming `dev:sync-from-prod` | `app/Infrastructure/Shopwired/Factories/CustomFieldFactory.php:124` | replaced: `dev:seed-fake` |
| `docs/deployment/*` runbooks (`railway run/up/logs`, e.g. `docker-production-deployment.md:464`) | `docs/deployment/` | production-only: `docs/deployment/` (human runbooks) |

---

## agent config

| Route | Evidence | Disposition |
|---|---|---|
| `railway` MCP server for Claude | `.mcp.json:2` | removed |
| `railway` MCP server for Codex | `.codex/config.toml` (block deleted in this branch) | removed |
| Auto-allowed `railway logs` / `railway service` | `.claude/settings.json:9-12` (denies added) | removed |
| `use-railway` skill (21 files) | `.agents/skills/use-railway/` deleted in this branch | removed |
| Console-command rule taught a `railway ssh` example | `presentation-console-commands.md:22` | replaced: human-in-production docblock block |
| Hand-run `railway` CLI (`railway run` injects production vars) | `AGENTS.md:48` | production-only: human, see `AGENTS.md` "Production Access" |
| Agent production log reads | `AGENTS.md:47` | production-only: `~/.agents/bin/railway-logs` (AGN-196, read-only) |
| `verify.sh --env-override` could set a live opt-in | `.agents/skills/verify/scripts/verify.sh:416` | replaced: refuses every `*_LIVE` key (exit 64) |
