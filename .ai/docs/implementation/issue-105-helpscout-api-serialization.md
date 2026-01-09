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

### Phase 1: Domain Object Modifications
- [ ] `ConversationAssignee.php` - Add `?string $email`
- [ ] `ConversationSnooze.php` - Add `?bool $unsnoozeOnCustomerReply`
- [ ] `Conversation.php` - Add `?string $customerWaitingFriendly`

### Phase 2: Response DTO Modifications
- [ ] `AssigneeResponse.php` - Pass `email` to Domain
- [ ] `SnoozeResponse.php` - Pass `unsnoozeOnCustomerReply` to Domain
- [ ] `ConversationResponse.php` - Pass `customerWaitingSince.friendly` to Domain

### Phase 3: Create API Resources
- [ ] `CustomerResource.php`
- [ ] `AssigneeResource.php`
- [ ] `TagResource.php`
- [ ] `SnoozeResource.php`
- [ ] `ConversationResource.php`

### Phase 4: Controller Integration
- [ ] Update all 8 endpoints to use Resources

### Phase 5: Testing
- [ ] Add feature test for API contract validation

## Files Changed
*(Updated as implementation progresses)*

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
