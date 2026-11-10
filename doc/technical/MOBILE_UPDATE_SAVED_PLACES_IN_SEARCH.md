# Saved Places Now Show Up in Location Search

**Date:** November 10, 2026
**Branch:** `feature/saved-places-in-search-suggestions` (PR #172)
**For:** Passenger app team
**Driver app:** not affected.

Full details: `API_REFERENCE.md` → **Place Search (Autocomplete)**. Postman: **Places → Search Places**.

---

## What's new

`GET /places/search` now mixes the passenger's own saved places (Home, Work, custom) into the results when they match what's typed — the same behaviour as Bolt's "My abode" showing up while typing, as in the screenshots that prompted this.

- Matching is against the saved place's **name or address**, case-insensitive. Typing "Laniba" matches a saved place named "My abode" whose address is "Ojoo/Ajibode/Laniba, Nigeria" even though "Laniba" isn't in the name.
- Saved-place matches are listed **first**, ordered Home → Work → custom, ahead of the geocoder results. Everything is still capped at `limit` (default 10) combined.
- If a geocoder result is essentially the same location as a matched saved place, it's dropped so you don't show the same address twice.
- Nothing changes for drivers — they only ever get geocoder results.

## Response shape

Every place in `places` now carries a `source`:

```json
{
  "places": [
    {
      "id": "6f1b2a3c-7e2d-4a3b-9c1d-5e6f7a8b9c0d",
      "name": "My abode",
      "address": "Ojoo/Ajibode/Laniba, Nigeria",
      "latitude": 7.4451,
      "longitude": 3.9077,
      "source": "saved_place",
      "saved_place_type": "custom"
    },
    {
      "id": "place_abc123",
      "name": "Alakia Junction Bus Stop",
      "address": "Ibadan, Nigeria",
      "latitude": 7.4001,
      "longitude": 3.9321,
      "source": "search"
    }
  ]
}
```

- `source`: `"saved_place"` or `"search"`.
- `saved_place_type` is only present when `source` is `"saved_place"`: `"home"`, `"work"`, or `"custom"`. Use it to pick the row icon — a house for `home`/`work`, whatever you already use for a saved custom place, and your normal pin for `"search"` rows.
- No other fields changed. `id` for a saved-place row is its saved-place UUID (the same `id` you'd use against `/passenger/saved-places/{id}` if you ever need it), not a provider place id.

## Nothing to change if you don't want this yet

Older app builds that ignore `source` keep working exactly as before — they'll just show saved places mixed into the list without the special icon treatment, which is a reasonable default. There's no flag to opt out; the merge only activates for passengers, and only when something in the query matches one of their saved places.

---

## Suggested UI

Same as the current "Saved places" screen's iconography:
- Row for a `saved_place` match: icon from `saved_place_type` (house for Home/Work, your custom-place icon otherwise), name in the first line, address as the subtitle — no distance needed.
- Row for a `search` match: your existing pin-based suggestion row, unchanged.
