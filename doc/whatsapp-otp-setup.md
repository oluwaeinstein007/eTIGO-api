# WhatsApp OTP & Social Auth — Setup Guide

This document lists what the development team needs from you (the client / business owner) to enable WhatsApp OTP login and social OAuth for the e-tiGO app.

---

## 1. WhatsApp Business Cloud API (for OTP delivery)

We use Meta's WhatsApp Cloud API to send one-time verification codes to users via WhatsApp.

### What you need to do

1. **Create a Meta Business account** at [business.facebook.com](https://business.facebook.com) (if you don't already have one).
2. **Create a Meta App** at [developers.facebook.com](https://developers.facebook.com/apps/) — select "Business" type.
3. **Add the WhatsApp product** to the app (from the app dashboard, click "Add Product" → WhatsApp).
4. **Complete business verification** — Meta requires this for production access. Go to Business Settings → Security Center → Start Verification.
5. **Register a phone number** — this is the number that will appear as the sender (e.g. your business number). You can use Meta's test number during development.
6. **Create an OTP message template** — in the WhatsApp Manager, create a message template:
   - **Name:** `otp_verification`
   - **Category:** Authentication
   - **Language:** English
   - **Body:** `Your e-tiGO verification code is: {{1}}`
   - Submit for approval (usually approved within minutes for auth templates).

### What to send the dev team

| Variable | Where to find it |
|---|---|
| `WHATSAPP_PHONE_NUMBER_ID` | WhatsApp > API Setup > Phone Number ID |
| `WHATSAPP_ACCESS_TOKEN` | WhatsApp > API Setup > Generate permanent token (or use System User token for production) |
| `WHATSAPP_OTP_TEMPLATE` | The template name you created (default: `otp_verification`) |

> **Production note:** For production, create a System User in Business Settings, assign it to the WhatsApp app with `whatsapp_business_messaging` permission, and generate a permanent access token. The temporary token from the dashboard expires after 24 hours.

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

---

## 4. Facebook OAuth (Sign in with Facebook)

### What you need to do

1. Go to [Meta for Developers](https://developers.facebook.com/apps/).
2. Use the same app from the WhatsApp setup above (or create a separate one).
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
# WhatsApp Cloud API
WHATSAPP_PHONE_NUMBER_ID=your_phone_number_id
WHATSAPP_ACCESS_TOKEN=your_access_token
WHATSAPP_OTP_TEMPLATE=otp_verification

# Google
GOOGLE_CLIENT_ID=your_google_client_id

# Apple
APPLE_CLIENT_ID=com.etigo.service

# Facebook
FACEBOOK_APP_ID=your_facebook_app_id
FACEBOOK_APP_SECRET=your_facebook_app_secret
```

Send these values securely (not over plain email — use a password manager share, or an encrypted channel).
