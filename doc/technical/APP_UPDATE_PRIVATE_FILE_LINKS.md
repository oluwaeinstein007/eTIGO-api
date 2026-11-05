# Private File Links: What the Apps Should Know

**Date:** November 5, 2026 (updated: authenticated links replace 30-minute signed links)
**Backend:** branch `fix/authenticated-file-links`; live on staging once merged
**Prepared for:** Driver app, passenger app and admin dashboard teams

Uploaded documents and evidence are private. Their links no longer expire. Instead, **each link opens only with the bearer token of the user it was given to**, so the apps must send `Authorization: Bearer {token}` when loading a file. Field names and upload requests are unchanged, and profile photos stay public.

This replaces the 30-minute signed links from earlier today (Toyyib's suggestion): no refetching after 30 minutes, links can be cached, and a copied link is useless to anyone else.

Request and response details are in `API_REFERENCE.md` (**File links** at the top, and **Get File**). Base URL: `/api/v1`.

---

## Summary

| App | Must do | Should do |
|---|---|---|
| Driver | 1. Send the bearer token when loading private files | 3. Cache files by link |
| Passenger | 1. Same | 3. Same |
| Admin dashboard | 2. Fetch private files with the token and show them from a blob URL (`<img src>` can't send the token) | 3. Same |

Nobody has to change how files are **uploaded**.

---

## Which links changed

| Private (authenticated API link) | Public (unchanged) |
|---|---|
| Driver documents and vehicle papers (`file_url`) | `profile_photo_url` (passengers and drivers) |
| Dispute evidence (`evidence[].url`, `evidence_photos`, `evidence_audio`) | |
| Lost & Found photos (`photos`) | |
| Repair request attachments (`attachments[].file_url`) | |
| Vehicle maintenance proofs (`proof_url`) | |

**Now:** `https://{api-host}/api/v1/files/{ref}`, for example `https://etigo-api.apeinhood.xyz/api/v1/files/ZHJpdmVy…bGljZW5jZS5wZGY.3f9c2a7e…`

- **With the right token:** 200 and the file itself, inline, with its real content type (`image/jpeg`, `application/pdf`, `audio/mp4`…).
- **No token:** 401. **Another user's token**, or the file was deleted (such as a replaced document): 404.
- **Never expires.** It stops working once that user's sign-in (token) ends. The same file gives the same link in every response.
- The 30-minute `api.cloudinary.com/...download` links are no longer returned.

---

## 1. Mobile apps: send the token with file requests (must do)

Load private files with the same `Authorization: Bearer {token}` header as API calls. Most widgets accept headers:

- **Images:** `Image.network(url, headers: {...})` or `CachedNetworkImage(imageUrl: url, httpHeaders: {...})` (Flutter); `<Image source={{ uri: url, headers: {...} }} />` (React Native).
- **PDFs and audio:** pass the same headers to the PDF viewer or audio player, or download the file with the header and open it locally.
- Only add the header for links that contain `/api/v1/files/`. Profile photos are public and don't need it.

## 2. Admin dashboard: fetch, then show a blob (must do)

A browser can't attach the bearer token to `<img src>` or to a link opened in a new tab. Fetch the file, then show it locally:

```js
const res = await fetch(fileUrl, { headers: { Authorization: `Bearer ${token}` } });
if (!res.ok) { /* 404: not available; 401: sign in again */ }
const src = URL.createObjectURL(await res.blob());
// <img src={src}>, <audio src={src}>, or window.open(src) for a PDF
// URL.revokeObjectURL(src) when the view closes
```

A small `useAuthedFile(url)` hook or `<AuthedImage url>` component covers driver review, disputes, Lost & Found, repairs and maintenance in one place.

## 3. Cache by link (should do, all apps)

The link is stable and the response allows private caching for 24 hours (`Cache-Control: private, max-age=86400`). Image caches keyed by URL work, and you no longer need to refetch records just to renew links. Don't add Cloudinary transformations (such as `w_200`); resize on the device.

---

## Also part of the private-files work (no app work)

Driver ID numbers (NIN, licence, plate) and the personal details KYC matched are encrypted in the database. The API returns the same fields and values as before.
