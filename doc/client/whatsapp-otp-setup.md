# OTP & Social Auth — Setup Guide

This document lists what the development team needs from you (the client / business owner) to enable OTP login and social OAuth for the e-tiGO app.

---

## 1. Sendchamp (OTP codes, alerts and rider messages)

We use [Sendchamp](https://my.sendchamp.com) to send verification codes, SOS alerts, payout-account alerts, Lost & Found texts and messages to riders booked by someone else. Each message goes by **WhatsApp first** and falls back to **SMS** automatically if WhatsApp cannot deliver it.

The account **EtiGo Rides** is already connected (the dev team has the access key). What is still needed:

### What you need to do

1. **Fund the Sendchamp wallet.** Every SMS and WhatsApp message is paid from it. While the balance is ₦0, Sendchamp refuses every message, so customers cannot receive login codes. Keep it topped up (the dev team can check the balance with `php artisan sendchamp:status`).
2. **Get the SMS sender name `Etigo` approved.** This is the name customers see on SMS. In the dashboard go to **SMS → Sender ID** and request `Etigo` (use case: *Transactional*), or ask the dev team to submit it for you. Approval can take a few days; until then SMS goes out under Sendchamp's default name, `Sendchamp`.
3. **Connect a WhatsApp number.** In the dashboard go to **WhatsApp → Overview** and connect E-tiGo's WhatsApp Business number. (For testing, Sendchamp's shared number `2348120678278` can be used.)
4. **Create two WhatsApp templates** under the WhatsApp channel and wait for Meta to approve them. WhatsApp only allows businesses to start a conversation with an approved template, so without these everything goes by SMS.

   | Template | Category | Suggested text |
   |---|---|---|
   | Verification code | **Authentication** | `{{1}} is your E-tiGo verification code. For your security, do not share this code.` |
   | Notifications | **Utility** | `E-tiGo update: {{1}} This is an automated message from E-tiGo.` |

   Each template must have exactly **one** variable, `{{1}}`. The code goes into the first one; the whole message (driver details, alerts and so on) goes into the second. Keep some fixed text before and after `{{1}}`, because Meta rejects templates that start or end with a variable.
5. **Leave the Webhook URL empty** for now. The platform does not read delivery reports yet.

### What to send the dev team

| Variable | Where to find it |
|---|---|
| `SENDCHAMP_SENDER_NAME` | `Etigo`, once approved (until then `Sendchamp`) |
| `SENDCHAMP_WHATSAPP_SENDER` | The WhatsApp number connected in step 3 (international format, e.g. `2348012345678`) |
| `SENDCHAMP_WHATSAPP_OTP_TEMPLATE` | The **template code** of the verification-code template (WhatsApp → Templates) |
| `SENDCHAMP_WHATSAPP_TEMPLATE` | The **template code** of the notifications template |
| `ETIGO_SAFETY_NUMBER` | The phone number that receives SOS emergency alerts (E.164 format, e.g. `+234...`) |

### How delivery works

- **Login and bank-account codes** go by WhatsApp (verification-code template) first, then by SMS. The SMS reads: *"Your E-tiGo verification code is 482910. It expires in 5 minutes. Do not share it with anyone."*
- **SOS alerts, payout-account alerts, Lost & Found texts and rider messages** go by WhatsApp (notifications template) first, then by SMS.
- SMS uses the DND route, so it also reaches numbers registered on the NCC Do-Not-Disturb list.
- In **non-production** environments (local, staging, testing), the login code is always `123456` and no code is sent. Other messages are sent when a Sendchamp key is set.

---

## 2. Google OAuth (Sign in with Google)

### What you need to do

1. Go to [Google Cloud Console](https://console.cloud.google.com/).
2. Create a project (or use an existing one).
3. Go to **APIs & Services** → **OAuth consent screen** → Configure for "External" users.
4. Go to **APIs & Services** → **Credentials** → Create **OAuth 2.0 Client IDs**:
   - Create one for **Android** (needs your app's package name and SHA-1 fingerprint).
   - Create one for **iOS** (needs your app's bundle ID).
   - Create one for **Web** (if needed for admin panel or testing).

### What to send the dev team

| Variable | Where to find it |
|---|---|
| `GOOGLE_CLIENT_ID` | The **Web** Client ID (or Android — the mobile team will clarify which one they use for ID token verification) |
| `GOOGLE_CLIENT_SECRET` | Credentials → OAuth 2.0 Client IDs → Client secret |

---

## 3. Apple OAuth (Sign in with Apple)

### What you need to do

1. Go to [Apple Developer Portal](https://developer.apple.com/account/).
2. Under **Certificates, Identifiers & Profiles** → **Identifiers**, register an App ID with "Sign in with Apple" enabled.
3. Under **Keys**, create a key with "Sign in with Apple" enabled. Download the key file.
4. Under **Identifiers** → **Services IDs**, register a Service ID (this is your client ID for server-side verification).

### What to send the dev team

| Variable | Where to find it |
|---|---|
| `APPLE_CLIENT_ID` | The Service ID identifier (e.g. `com.etigo.service`) |
| `APPLE_CLIENT_SECRET` | Generated JWT from your private key (see [Apple docs](https://developer.apple.com/documentation/sign_in_with_apple/generate_and_validate_tokens)) |

---

## 4. Facebook OAuth (Sign in with Facebook)

### What you need to do

1. Go to [Meta for Developers](https://developers.facebook.com/apps/).
2. Create a new app (or use an existing one).
3. Add the **Facebook Login** product.
4. Under **Settings** → **Basic**, note the App ID and App Secret.
5. Under **Facebook Login** → **Settings**, add your app's redirect URIs (the mobile team will provide these).

### What to send the dev team

| Variable | Where to find it |
|---|---|
| `FACEBOOK_APP_ID` | Settings → Basic → App ID |
| `FACEBOOK_APP_SECRET` | Settings → Basic → App Secret |

---

## Summary — All env values needed

```env
# Sendchamp (WhatsApp templates + SMS fallback)
SENDCHAMP_ACCESS_KEY=already_set_by_dev_team
SENDCHAMP_SENDER_NAME=Etigo
SENDCHAMP_WHATSAPP_SENDER=2348xxxxxxxxx
SENDCHAMP_WHATSAPP_OTP_TEMPLATE=your_otp_template_code
SENDCHAMP_WHATSAPP_TEMPLATE=your_notification_template_code
ETIGO_SAFETY_NUMBER=+234xxxxxxxxxx

# Google
GOOGLE_CLIENT_ID=your_google_client_id
GOOGLE_CLIENT_SECRET=your_google_client_secret

# Apple
APPLE_CLIENT_ID=com.etigo.service
APPLE_CLIENT_SECRET=your_apple_client_secret

# Facebook
FACEBOOK_APP_ID=your_facebook_app_id
FACEBOOK_APP_SECRET=your_facebook_app_secret
```

Send these values securely (not over plain email — use a password manager share, or an encrypted channel).
