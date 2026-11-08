# WhatsApp Messages: Fare Fallback Transparency Update

Ready-to-send drafts for the three app teams. Backend details are in `API_REFERENCE.md` (Get Fare Estimates section) and the "E-tiGo API v1" Postman collection — all three messages link back to those instead of repeating the schema.

---

## 1. Passenger app dev

> Backend update (staging): `POST /rides/estimate` and `POST /rides` now return two new fields on every quote — `distance_source` (`"google"` / `"mapbox"` / `"haversine"`) and `is_approximate` (true only for `"haversine"`).
>
> This matters because if both Google and Mapbox routing fail, we fall back to a straight-line distance estimate instead of the real road route. It's usually close, but can be noticeably off, especially across the Lagoon or through gated areas.
>
> **One thing needed from you:** when `is_approximate` is `true` on an estimate, please show a visible "approximate fare" notice on the quote screen — something like "Estimated fare (routing temporarily limited, may differ from final fare)". You'll also see it already in the `warnings` array as a ready-made sentence if you'd rather just surface that.
>
> Also new: if the only available distance is approximate **and** the trip is over 40km, the estimate endpoint now returns `422` with `error_code: "FARE_ESTIMATE_UNAVAILABLE"` instead of a wrong long-trip quote. Please handle that like the other estimate errors — show the message and let them retry in a bit.
>
> No changes to anything else on the estimate/booking flow. Full examples are in Postman under "Get Fare Estimates" → new "422 — Fare Estimate Unavailable" response.

---

## 2. Driver app dev

> Backend update (staging): nothing you need to change in the app. We added fields to the fare quote (`distance_source`, `is_approximate`) so passengers see when a fare is an approximate estimate instead of a routed one, and we stopped surge pricing from applying on top of those approximate quotes.
>
> One thing worth knowing even though it's not a code change for you: on the rare ride where routing fails and the fare falls back to an approximate distance, the fare (and so your earnings on that trip) can come out a bit lower than usual, since surge is skipped and the distance is a straight-line estimate rather than the routed one. The payout *formula* (your % of the fare) is unchanged — just the fare it's applied to can be slightly different on those specific trips. This should be rare; flagging it so it's not a surprise if you ever see it on a trip.
>
> No UI/API change needed from you. You may see `distance_source`/`is_approximate` show up in a ride payload — safe to ignore.

---

## 3. Admin dashboard dev

> Backend update (staging): ride details (`GET /admin/rides/{ride}` and anywhere else `pricing_snapshot` shows up) now include `distance_source` and `is_approximate` at the top level, same place as `distance_km`/`duration_minutes`.
>
> Context: when our routing providers (Google, Mapbox) are both down, we fall back to a straight-line distance estimate. That's always existed, but until now there was no way to tell which rides were priced that way. Now every ride's pricing snapshot records it.
>
> **Useful for you:** when investigating a fare dispute or doing pricing-accuracy analysis, check `is_approximate` first — if `true`, the fare was based on an estimate, not a routed distance, so a passenger/driver complaint about a seemingly-off fare may be explained by that rather than a bug. We'd also suggest (not urgent, your call) a small badge/icon on the ride detail view next to the fare when `is_approximate` is true, so support agents can spot it at a glance without reading the raw snapshot.
>
> No endpoint changes, no breaking changes — just two new fields alongside what's already there.
