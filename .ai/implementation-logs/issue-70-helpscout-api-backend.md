# Implementation Log: HelpScout API Backend Migration

**GitHub Issue**: #70
**Plan Document**: docs/plans/2025-12-11_70-helpscout-api-backend-migration.md
**Status**: In Progress
**Started**: 2025-12-11
**Completed**: —

## Overview

Migrate HelpScout API integration from React frontend to Laravel backend, enabling full `snooze` field support via direct HTTP while using SDK for OAuth2 authentication. Serves 5 REST endpoints for dashboard widgets.

## Decision Log

### 2025-12-11
- **Decision**: Use direct HTTP for conversations instead of SDK
- **Why**: SDK drops `snooze` field during hydration, which all 4 widget types need
- **Tradeoff**: More code than SDK wrapper, but full field support

- **Decision**: IP-restricted local bypass (localhost + `local` environment)
- **Why**: Extra security layer beyond just environment check
- **Tradeoff**: Slightly more restrictive but safer

- **Decision**: Use React schemas as DTO source of truth
- **Why**: Frontend schemas were smoke-tested against real API, SDK is incomplete
- **Tradeoff**: Need to cross-reference two codebases

## Deviations from Plan

None yet.

## Blockers / Open Questions

- [ ] Verify SDK's OAuth2 authenticator works with `HelpScout::getAuthenticator()->getAuthHeader()`
- [ ] Confirm `config.dashboard` table schema matches expected escalation settings

## Technical Notes

- HelpScout API uses camelCase, PHP DTOs use snake_case internally
- `MapInputName(SnakeCaseMapper::class)` for parsing, `MapOutputName` for responses
- SDK auto-refreshes OAuth2 tokens - we leverage this for HTTP requests too

## PR Notes

### What
Backend REST API serving HelpScout dashboard widgets - conversations, escalations, mailboxes.

### Why
- SDK silently drops `snooze` field needed by all widgets
- Centralize data transformation and caching in backend
- Prepare for future HelpScout integrations (customers, threads)

### Key Decisions
- Direct HTTP for conversations (snooze support), SDK for OAuth2 only
- User email → HelpScout ID mapping with 7-day cache
- IP-restricted local testing bypass

### Testing
- Unit tests for Config, Transport, Client, CachingService
- Feature tests for all 5 endpoints with mocked HTTP responses