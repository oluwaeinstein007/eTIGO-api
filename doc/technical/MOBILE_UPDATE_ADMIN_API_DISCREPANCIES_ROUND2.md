# Admin Dashboard Fixes (Round 2): What the Apps Should Know

**Date:** November 8, 2026
**Backend:** branch `fix/admin-api-discrepancies-round2`; live on staging once merged
**Prepared for:** Driver app team, Passenger app team.

**Nothing in the driver app or the passenger app has to change.**

Every fix in this round is scoped to the admin dashboard: new admin endpoints, admin-only fields, admin role permissions, admin list ordering, and an admin query bug. None of it touches a driver- or passenger-facing endpoint's request or response shape, except two public lookup endpoints whose *behaviour* improved in a backward-compatible way:

- `GET /vehicle-makes` and `GET /vehicle-makes/{make}/models` — the `search` query param now matches regardless of case (`?search=toyota` now finds "Toyota"). Anything that already worked (exact case, or no search) is unaffected; this only makes search more forgiving. One edge case: makes/models are unique case-sensitively, so if the catalog ever has both e.g. "Toyota" and "TOYOTA" as separate rows, a search for either now returns both where it used to return at most one. If your make/model picker assumes a search can only ever match one entry, add simple disambiguation (show both, let the person pick) rather than assuming the first result.

Everything else fixed in this round (vehicle maintenance record types, ride refund visibility for finance, repair request driver ids, vehicle catalog management, driver document fields, list ordering, refund history) lives entirely under `/admin/*` and is covered in `doc/technical/API_REFERENCE.md` and the PR description for the admin frontend team.

No action needed from the driver or passenger app teams for this round.
