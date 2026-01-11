# Issue #105: HelpScout API Serialization Fix

## Context
- **Issue**: All 4 HelpScout dashboard tables display "Received invalid data from server"
- **Root Cause**: Laravel serializes Domain objects differently than Zod schemas expect
- **Branch**: `hotfix/105-fix-helpscout-api-serialization-breaks-alz-admin-dashboard-tables`

## Decision Log

| Decision | Rationale | Date |
|----------|-----------|------|
| Add Laravel API Resources | Transform Domain → API contract format; keeps Domain clean for business logic | 2026-01-10 |
| Add missing fields to Domain objects | Single source of data for both business logic and serialization | 2026-01-10 |
| Use `array_filter` for null filtering | Omit null fields from response to match HelpScout API behavior | 2026-01-10 |

## Implementation Progress

### Phase 1: Domain Object Modifications ✅
- [x] `ConversationAssignee.php` - Add `?string $email`
- [x] `ConversationSnooze.php` - Add `?bool $unsnoozeOnCustomerReply`
- [x] `Conversation.php` - Add `?string $customerWaitingFriendly`

### Phase 2: Response DTO Modifications ✅
- [x] `AssigneeResponse.php` - Pass `email` to Domain
- [x] `SnoozeResponse.php` - Pass `unsnoozeOnCustomerReply` to Domain
- [x] `ConversationResponse.php` - Pass `customerWaitingSince.friendly` to Domain

### Phase 3: Create API Resources ✅
- [x] `CustomerResource.php` - Maps `firstName/lastName` → `first/last`
- [x] `AssigneeResource.php` - Includes email, filters nulls
- [x] `TagResource.php` - Maps `name` → `tag`
- [x] `SnoozeResource.php` - Maps `snoozedByUserId` → `snoozedBy`, formats dates
- [x] `ConversationResource.php` - Orchestrates all, maps `customer` → `primaryCustomer`

### Phase 4: Controller Integration ✅
- [x] Update all 8 endpoints to use `ConversationResource::collection()`
- [x] Add `*Resource` to PHPArkitect naming rules

### Phase 5: Testing ✅
- [x] Add feature test for API contract validation (`ConversationApiContractTest.php`)
- [x] 23 tests covering: date formatting, field mappings, null omission, nested resources

## Files Changed

**Domain Objects Modified:**
- `app/Domain/CustomerService/ValueObjects/Conversation.php`
- `app/Domain/CustomerService/ValueObjects/ConversationAssignee.php`
- `app/Domain/CustomerService/ValueObjects/ConversationSnooze.php`

**Response DTOs Modified:**
- `app/Infrastructure/HelpScout/Responses/AssigneeResponse.php`
- `app/Infrastructure/HelpScout/Responses/SnoozeResponse.php`
- `app/Infrastructure/HelpScout/Responses/ConversationResponse.php`
- `app/Infrastructure/HelpScout/Responses/CustomerWaitingSinceResponse.php`

**API Resources Created:**
- `app/Presentation/Http/Resources/HelpScout/CustomerResource.php`
- `app/Presentation/Http/Resources/HelpScout/AssigneeResource.php`
- `app/Presentation/Http/Resources/HelpScout/TagResource.php`
- `app/Presentation/Http/Resources/HelpScout/SnoozeResource.php`
- `app/Presentation/Http/Resources/HelpScout/ConversationResource.php`
- `app/Presentation/Http/Resources/HelpScout/CLAUDE.md`

**Controller Updated:**
- `app/Presentation/Http/Controllers/HelpScoutController.php`

**Architecture Config:**
- `phparkitect.php` - Added `*Resource` naming rule

**Tests Created:**
- `tests/Feature/Presentation/Http/Resources/HelpScout/ConversationApiContractTest.php`

## PR Notes
*(Draft PR description here before creating)*

**Title:** fix: HelpScout API serialization to match frontend Zod schemas

**Summary:**
- Add Laravel API Resources to transform Domain objects to API contract format
- Add missing fields to Domain objects (email, unsnoozeOnCustomerReply, customerWaitingFriendly)
- Update Response DTOs to preserve previously discarded data
- Fixes all 4 HelpScout dashboard tables in alz-admin

**Test plan:**
- [ ] All 4 HelpScout tables load in alz-admin (Escalations, Assigned, To-Dos, Negative Reviews)
- [ ] No Zod validation errors in alz-admin logs
- [ ] Dates serialize as ISO 8601 strings
- [ ] Null fields omitted from response
