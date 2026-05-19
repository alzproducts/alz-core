# HelpScout API Resources

Transform Domain objects to match HelpScout API contract (${FRONTEND_APP} Zod schemas).

## Patterns

- **Dates**: Use `DateTimeInterface::ATOM` for ISO 8601 strings
- **Null fields**: Omit with `array_filter()` — never include as `null`
- **Field mappings**: `name→tag`, `snoozedByUserId→snoozedBy`, `customer→primaryCustomer`, `firstName/lastName→first/last`