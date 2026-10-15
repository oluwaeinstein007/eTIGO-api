# OTP & Social Auth — Setup Guide

This document lists what the development team needs from you (the client / business owner) to enable OTP login and social OAuth for the e-tiGO app.

---

## 1. Termii (for OTP & SOS alert delivery)

We use Termii to send one-time verification codes and SOS emergency alerts via WhatsApp, with automatic SMS fallback if WhatsApp delivery fails.

### What you need to do

1. **Create a Termii account** at [termii.com](https://termii.com).
2. **Register a Sender ID** — this is the name that appears as the sender for SMS messages (e.g. `Etigo`). Go to your Termii dashboard → Sender ID → Request a Sender ID.
3. **Enable WhatsApp channel** — in the Termii dashboard, set up your WhatsApp Business number for WhatsApp messaging.
4. **Get your API key** — go to your Termii dashboard → Settings → API Key.

### What to send the dev team

| Variable | Where to find it |
|---|---|
| `TERMII_API_KEY` | Termii Dashboard → Settings → API Key |
| `TERMII_SENDER_ID` | The Sender ID you registered (default: `Etigo`) |
| `ETIGO_SAFETY_NUMBER` | The phone number for SOS emergency alerts (E.164 format, e.g. `+234...`) |

### How delivery works

- **OTP codes** are sent via WhatsApp first. If WhatsApp delivery fails, the system automatically retries via SMS.
- **SOS emergency alerts** follow the same WhatsApp-first, SMS-fallback pattern and are sent to the configured safety number.
- In **non-production** environments (local, staging, testing), OTP is always `123456` and no messages are sent.

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
# Termii (WhatsApp + SMS fallback)
TERMII_API_KEY=your_termii_api_key
TERMII_SENDER_ID=Etigo
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
