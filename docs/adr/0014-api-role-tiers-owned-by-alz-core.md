# ADR-0014: alz-core owns its API role tiers, independent of the Admin Dashboard's path gating

**Context.** The Admin Dashboard restricts **Roles** by page path (guest → `/g` and `/user` only; `/admin` → manager and admin), and its `role-permissions.ts` governs only who may assign which role. Neither is an API permission matrix, so the API had no role checks at all (COR-251).

**Decision.** alz-core defines its own **Role tiers** in code and applies them per route group: the Staff tier on every approval-gated route, the Admin-area tier on the custom-field-definition settings routes. A missing or unrecognised role is denied. **Admin-only fields** are restricted to the Admin-area tier, which is enforced per field in the custom-field use cases, because route middleware cannot see which fields a request touches. There is no admin-exclusive tier: the dashboard has none either. The initial tiers were derived from what the dashboard's pages effectively allowed; afterwards the two repos are aligned by review, not by a shared file.

**Considered options.**
- **Shared matrix both repos load** (DB table or JSON): rejected — a new cross-repo contract with drift and deploy-order risk, and the frontend's path rules would still need their own mapping.
- **Treat the frontend as canonical**: rejected — its rules are page paths, which do not map onto API routes, and the server is the security boundary.
