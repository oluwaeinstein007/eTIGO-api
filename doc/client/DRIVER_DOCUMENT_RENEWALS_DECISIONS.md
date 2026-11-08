# Driver Document Renewals: Decisions for Confirmation

**Date:** 7 November 2026 (DR-11 and DR-12 added 8 November 2026)  
**Status:** Built. **Approved by Augustine Opara for E-TiGO on 8 November 2026**, including adding the Lagos LASDRI and LASSRA cards (DR-12). Every value in section 2 can be changed by Operations in the dashboard without a new release.

## 1. The approach

- **No yearly renewal.** A driver renews a paper when the date printed on it runs out. Nigerian driving licences are issued for 3 or 5 years, so a yearly upload would be unnecessary.
- **Renewal is not a new KYC.** Identity (NIN, licence verification, selfie) is settled at onboarding. A renewal is a document check by Operations: the expiry date, the name, and whether it belongs to the same driver. The driver keeps their account, ratings, earnings and history.
- **No gap while a renewal is checked.** The licence on file stays valid until the renewed one is approved. A driver who renews early never loses a day of driving.
- **An expired driving licence stops driving.** The rule is enforced on the server, not only hidden in the app: no going online, no new trips, no manual assignment, no fleet shifts, and reserved pickups go back to other drivers. **A trip in progress is never interrupted.**
- **Reminders go out by push, in-app message and email.** Push alone isn't enough, because drivers turn notifications off.
- **Vehicle papers follow the same idea.** Vehicle licence, insurance and roadworthiness are tracked by the expiry date on each paper, not on a fixed yearly cycle, because each one runs out on a different date. Drivers are reminded before expiry and the car is taken off the road 7 days after a paper expires, until the renewed paper is approved.

## 2. Choices made (please confirm or change)

| # | Decision | Built as | Alternatives |
|---|---|---|---|
| DR-1 | When an expired licence blocks | The day **after** the expiry date (no grace period) | A grace period of a few days, as for vehicle papers (7 days) |
| DR-2 | Reminder schedule | 30, 14, 7 and 1 days before, on the day, and once expired | Fewer steps, or a 60-day first reminder |
| DR-3 | Channels | Push + in-app + email | Add SMS for the last reminder and the block (costs per message) |
| DR-4 | Government ID expiry (e.g. passport) | Reminds only; never blocks | Block, as for the licence |
| DR-5 | Licence found invalid after approval (suspended by FRSC, forged) | Operations **withdraw** it with a reason, which blocks at once | Suspend the whole account instead |
| DR-6 | Licence number changes on renewal | The driver sends the new number with the renewed licence; it's updated on approval. Approved drivers can no longer edit it in their profile | Allow profile edits |
| DR-7 | FRSC temporary licence while the card is printed | Accepted, approved with its own short expiry date, so the reminders and the block follow that date. A renewal payment receipt is **not** accepted | Don't accept temporary papers |
| DR-8 | Licences approved before this change, with no expiry date on file | Not blocked. Operations add the dates from a "missing expiry date" list | Ask every driver to re-upload |
| DR-9 | Who may withdraw or correct a paper | Operations and Safety | Operations only |
| DR-10 | Fleet drivers | Same rule. Operations also get an email when a fleet driver is blocked | — |
| DR-11 | Where the expiry date comes from | The driver enters it and Operations confirm it against the photo. A renewed licence is also looked up in FRSC records (through QoreID, the check used at onboarding): the reviewer sees whether FRSC knows the licence in the driver's name and FRSC's expiry date, and a mismatch is flagged. FRSC's date is used when no date was entered. Operations still decide | Driver's date only (no lookup); or approve automatically when FRSC matches |
| DR-12 | Lagos LASDRI and LASSRA cards | Required from drivers in Lagos (set per city, so other cities can be added later). New Lagos applicants must upload both before submitting; the LASDRI card needs its expiry date. Drivers already working in Lagos are **reminded every 14 days, not blocked**, until they upload them. An expiring LASDRI card gets the usual reminders and never blocks | Block Lagos drivers without the cards, possibly after a deadline |

## 3. Out of scope for now

- **Periodic selfie checks** to confirm the person driving is the account holder. This is a separate safety feature.
