# Pricing Coverage Warnings: Admin Dashboard Updates

**Date:** October 16, 2026  
**Backend:** PR #97 (`feat/pricing-coverage-warnings`); live on staging once merged  
**Prepared for:** Admin dashboard team

Passengers only get a fare for a vehicle class in a city when three things are true: the class is turned on overall, it is turned on for that city, and it has a pricing config in effect (`effective_from` in the past). When none of a city's classes meets all three, the passenger app shows "No pricing available for this city. Please try a different city." This happened to Lekki Test on staging, and nothing in the dashboard said anything was wrong.

The API now flags these setups. Request and response details are in `API_REFERENCE.md` → **Admin — Pricing Management** → **Pricing coverage warnings**. The new endpoint has a saved example in the "E-tiGo API v1" Postman collection (**Admin › Admin — Pricing Management › List Pricing Coverage Gaps**). Base URL: `/api/v1`.

---

## Summary

| Must do (breaking) | Should do | Nice to have |
|--------------------|-----------|--------------|
| Nothing. All changes are new fields and a new endpoint | Show `warnings` after saving, and on the city and vehicle class detail pages | "Unpriced" badge in the city list; a "Pricing gaps" view |

Warnings never block a change: the save has already succeeded when you receive them. Treat them as notices, not errors.

---

## 1. Show `warnings` (should do)

These responses now include `warnings: string[]`. It is always present and empty when there is nothing to flag.

| Request | Typical warning |
|---------|-----------------|
| `POST /admin/pricing` | "Comfort is turned off, so this pricing won't be used until the vehicle class is turned on." |
| `GET /admin/cities/{id}` | "Passengers in Lekki Test can't get fare estimates: none of its vehicle classes (Comfort and Economy) has pricing in effect. Add pricing for at least one of them." |
| `POST /admin/cities`, `PUT /admin/cities/{id}` | Same as above |
| `PATCH /admin/cities/{id}/status` | Same as above, on activation |
| `PUT /admin/cities/{id}/vehicle-classes` | "Lekki Test is active but doesn't offer any vehicle classes, so passengers can't book rides there." |
| `GET /admin/vehicle-classes/{id}` | "Economy has no pricing in effect in Lekki Test. Passengers there won't get fares for it until pricing is added." |
| `POST /admin/vehicle-classes`, `PUT /admin/vehicle-classes/{id}` | "Economy is turned off, which leaves Lekki Test with no vehicle class passengers can book." |

The messages are written for admins. Show them as they are; don't parse them.

**After a save (POST/PUT/PATCH):**
1. Show your usual success toast.
2. If `warnings` is not empty, also show a notice that stays until it is dismissed. Use an amber/warning style and list each message. A toast that fades is easy to miss, and that is how this problem went unnoticed.

**On the city and vehicle class detail pages (GET):** show the same notice at the top of the page when `warnings` is not empty, so the problem stays visible until it is fixed. A link to the pricing page, filtered to that city or class, is the quickest fix for the admin.

```ts
type WithWarnings<T> = T & { warnings?: string[] };

function showSaveResult(res: WithWarnings<{ message: string }>) {
  toast.success(res.message);
  if (res.warnings?.length) {
    showWarningNotice(res.warnings); // persistent, dismissible, one line per message
  }
}
```

Use `warnings ?? []`, so the dashboard keeps working against an older backend.

---

## 2. "Unpriced" badge in the city list (nice to have)

`GET /admin/cities` now returns `unpriced_vehicle_classes_count` on each city. This is the number of classes the city offers that have no pricing in effect.

- When it is above 0, show a badge next to the city name, e.g. **"2 unpriced"** in amber.
- Make it more urgent (red) when the count equals the number of classes the city offers (`vehicle_classes_count`) and the city is active. In that case passengers there can't get any fare.
- Clicking the badge should open the pricing page or the gaps view filtered to that city.

---

## 3. "Pricing gaps" view (nice to have)

```
GET /admin/pricing/gaps?city_id={optional}&vehicle_class_id={optional}
```

Roles: Operations and Finance (same as the rest of Pricing).

```json
{
  "gaps": [
    {
      "city_id": "01a14034-2ff9-71fc-952d-b178fdfd604a",
      "city_name": "Lekki Test",
      "city_is_active": true,
      "vehicle_class_id": "7d6e10db-aedf-4442-9453-c9fb9b29778e",
      "vehicle_class_name": "Comfort",
      "next_effective_from": null
    }
  ],
  "total": 1
}
```

Suggested UI: a table (or a card on the Pricing page) with **City**, **City status**, **Vehicle class**, **Next pricing starts** (`next_effective_from` in Africa/Lagos time, or "None scheduled"), and an **Add pricing** button that opens the create-pricing form with the city and class already filled in. When `total` is 0, show "Every vehicle class offered in a city has pricing." A count of gaps in active cities also works well as a dashboard alert.

---

## Testing on staging

Use a test city and a spare vehicle class:

1. Turn a class on for the city without adding pricing. The city detail page and `GET /admin/pricing/gaps` should both show it.
2. Create pricing for a class that is turned off overall. The create response should warn that the class is turned off.
3. Create pricing that starts tomorrow for an unpriced class. The response should say there is no pricing until then.
4. Add pricing that starts now. The warning and the gap should both disappear.

> **Short version for chat:** "City, vehicle class and pricing admin responses now include `warnings: string[]` (always present, empty when fine). They flag vehicle classes passengers can't get fares for, e.g. pricing for a class that's turned off, or a city with no priced classes. Saves still succeed. Please show them as a persistent amber notice after saving and at the top of the city and vehicle class detail pages. Optional: `unpriced_vehicle_classes_count` badge in the city list, and a gaps table from `GET /admin/pricing/gaps`. Details: FRONTEND_UPDATE_PRICING_WARNINGS.md."
