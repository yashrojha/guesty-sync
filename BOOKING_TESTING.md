# Instant Booking — Testing & Implementation Guide

This document explains the complete booking flow, how to configure each component, how to test each stage, and what to look for in Guesty and Stripe dashboards.

---

## 1. Prerequisites & Setup

### 1.1 Plugin Settings

Go to **WordPress Admin → Guesty Sync → Settings**.

| Tab | Field | Value |
|-----|-------|-------|
| **API Settings** | Client ID | Your Guesty OAuth client ID |
| **API Settings** | Client Secret | Your Guesty OAuth client secret |
| **Booking Settings** | Stripe Publishable Key | `pk_test_…` (test) or `pk_live_…` (live) |
| **Booking Settings** | Guesty Payment Provider ID | *(optional — auto-detected if left blank)* |
| **Booking Settings** | Terms & Conditions URL | Link to your T&C page |
| **Booking Settings** | Privacy Policy URL | Link to your Privacy page |

> **Note on Payment Provider ID:** The plugin auto-detects this in priority order:  
> 1. Admin override (Booking Settings)  
> 2. `paymentProviderId` on the Guesty listing object  
> 3. `GET /v1/payment-providers/default` account-level default  
>  
> You only need to fill this in manually if auto-detection fails.

### 1.2 Flush Rewrite Rules

After activating or updating the plugin, go to **Settings → Permalinks** and click **Save Changes** to flush rewrite rules so `/instant-booking/` resolves correctly.

### 1.3 Stripe Test Mode

For testing use Stripe test cards:

| Scenario | Card Number | Exp | CVC |
|----------|-------------|-----|-----|
| Success | `4242 4242 4242 4242` | Any future | Any |
| Auth required | `4000 0025 0000 3155` | Any future | Any |
| Decline | `4000 0000 0000 9995` | Any future | Any |
| Insufficient funds | `4000 0000 0000 9995` | Any future | Any |

---

## 2. API Endpoints Used

### 2.1 Authentication

```
POST https://open-api.guesty.com/oauth2/token
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials&scope=open-api&client_id=XXX&client_secret=XXX
```

**Response:** `{ "access_token": "...", "expires_in": 86400 }`

Token is cached in WordPress options (`guesty_access_token`) with a 5-minute safety buffer before expiry.

---

### 2.2 Quote — Availability & Pricing Check

```
POST https://open-api.guesty.com/v1/quotes
Authorization: Bearer {token}
Content-Type: application/json

{
  "listingId": "abc123",
  "checkInDateLocalized": "2026-05-01",
  "checkOutDateLocalized": "2026-05-05",
  "guestsCount": 2,
  "source": "manual"
}
```

**Valid response:** `data.status === "valid"`  
**Error response:** `data.error.message` contains the reason (e.g. "Minimum stay is 3 nights").

> This call happens **server-side** on page load. If it fails, the page shows the **Dates Unavailable** error screen — no booking form is rendered.

The returned `data._id` is the **Quote ID** passed to the reservation creation step to lock in the price.

---

### 2.3 Listing Details (House Rules + Payment Provider)

```
GET https://open-api.guesty.com/v1/listings/{listingId}
Authorization: Bearer {token}
```

The plugin reads:
- `terms.cancellationPolicy` — cancellation policy code
- `terms.smokingAllowed`, `petsAllowed`, `partiesEventsAllowed`, `childrenNotAllowed`
- `paymentProviderId` — the Stripe payment provider ID linked to this listing

---

### 2.4 Default Payment Provider (Fallback)

```
GET https://open-api.guesty.com/v1/payment-providers/default
Authorization: Bearer {token}
```

**Response:**
```json
{
  "_id": "5fe4b21675087f01a3c5ab5b",
  "type": "STRIPE",
  "currency": "GBP",
  "name": "My Stripe Account"
}
```

Used if the listing does not have a `paymentProviderId` and no admin override is set.

---

### 2.5 Create / Upsert Guest

```
POST https://open-api.guesty.com/v1/guests-crud
Authorization: Bearer {token}
Content-Type: application/json

{
  "firstName": "Jane",
  "lastName": "Smith",
  "email": "jane@example.com",
  "phone": "+447700900123"
}
```

**Response:** `{ "_id": "guest_id_here", ... }`

The returned `_id` is the **Guest ID** used in subsequent calls.

> If a guest with the same email already exists in Guesty, the API upserts (updates) the record rather than creating a duplicate.

---

### 2.6 Create Reservation

```
POST https://open-api.guesty.com/v1/reservations
Authorization: Bearer {token}
Content-Type: application/json

{
  "listingId":             "abc123",
  "checkInDateLocalized":  "2026-05-01",
  "checkOutDateLocalized": "2026-05-05",
  "guestsCount":           2,
  "guestId":               "guest_id_here",
  "source":                "direct",
  "status":                "confirmed",
  "quoteId":               "quote_id_here"    ← locks in quoted price
}
```

**Response:** `{ "_id": "reservation_id", "confirmationCode": "XXXX", ... }`

> The `quoteId` field ensures Guesty uses the exact price that was quoted at page load, even if the base rate has changed in the meantime.

---

### 2.7 Attach Stripe Payment Method to Guest

```
POST https://open-api.guesty.com/v1/guests/{guestId}/payment-methods
Authorization: Bearer {token}
Content-Type: application/json

{
  "stripePaymentMethodId": "pm_xxxxxxxxxxxxx",
  "paymentProviderId":     "5fe4b21675087f01a3c5ab5b",
  "reservationId":         "reservation_id_here",
  "skipSetupIntent":       false
}
```

**Success:** HTTP 200 or 201  
**Failure:** Response body contains `error.message`

> `stripePaymentMethodId` is the `paymentMethod.id` returned by `stripe.createPaymentMethod()` on the frontend — it is **never the raw card number**.

---

### 2.8 Apply Coupon to Quote (Optional)

When a guest enters a coupon code in the summary sidebar:

```
POST https://open-api.guesty.com/v1/quotes
Authorization: Bearer {token}
Content-Type: application/json

{
  "listingId":             "abc123",
  "checkInDateLocalized":  "2026-05-01",
  "checkOutDateLocalized": "2026-05-05",
  "guestsCount":           2,
  "source":                "manual",
  "coupon":                "SUMMER20"
}
```

On success the frontend updates the displayed prices and the internal `GBK.quoteId` so the reservation is created with the discounted price.

---

## 3. Validation Logic

### 3.1 Client-side (URL Params, PHP)

| Check | Error Message |
|-------|---------------|
| `listing_id` missing | Redirect to properties archive |
| `check_in` or `check_out` missing | "Check-in and check-out dates are required." |
| Date not in `YYYY-MM-DD` format | "The dates provided are not in a valid format." |
| `check_in` is in the past | "The check-in date {date} is in the past." |
| `check_out <= check_in` | "Check-out must be after check-in." |

### 3.2 Live Availability (Guesty Quote API)

| Scenario | Error Displayed |
|----------|----------------|
| Dates blocked in calendar | Guesty error message (e.g. "These dates are already booked.") |
| Below minimum stay | "Minimum stay is X nights." |
| Above maximum stay | "Maximum stay is X nights." |
| Listing not active | Guesty error message |
| Network / API timeout | "Unable to verify availability right now." |

All of these display the **Dates Unavailable** screen with a "← Change Dates" button linking back to the property page.

---

## 4. Testing the Full Booking Flow

### Step 0 — Open the booking page

```
https://yoursite.com/instant-booking/?listing_id=YOUR_LISTING_ID&check_in=2026-05-01&check_out=2026-05-05&guest=2
```

**Expected:** Booking form renders with correct pricing in the sidebar.

---

### Step 1 — Validation Tests

#### a) Invalid date format
```
?listing_id=XXX&check_in=01-05-2026&check_out=05-05-2026&guest=2
```
**Expected:** "Dates Unavailable" screen — "not in a valid format."

#### b) Past check-in date
```
?listing_id=XXX&check_in=2020-01-01&check_out=2020-01-05&guest=2
```
**Expected:** "Dates Unavailable" screen — "is in the past."

#### c) Check-out before check-in
```
?listing_id=XXX&check_in=2026-05-10&check_out=2026-05-08&guest=2
```
**Expected:** "Dates Unavailable" screen — "Check-out must be after check-in."

#### d) Blocked / unavailable dates
Use dates you know are blocked in Guesty for the listing.  
**Expected:** "Dates Unavailable" screen with Guesty's specific error message.

#### e) Valid dates
Use known available dates.  
**Expected:** Full 3-step booking form renders with price in sidebar.

---

### Step 2 — Guest Details (Step 1 of form)

1. Leave fields blank → click Continue → validation errors shown
2. Enter invalid email → error shown
3. Fill all fields correctly but leave T&C checkbox unchecked → error shown
4. Fill everything correctly and check T&C → click Continue
5. **Expected:** AJAX call to `guesty_create_booking_guest` succeeds, Step 2 expands

**Verify in Guesty:** Guest appears under Guests with correct name/email.

---

### Step 3 — Rules & Policies (Step 2 of form)

1. Click Continue without checking the policy checkbox → error shown
2. Check the checkbox → click Continue
3. **Expected:** Step 3 expands, Stripe card element mounts

---

### Step 4 — Payment (Step 3 of form)

1. Enter Stripe test card `4242 4242 4242 4242`, expiry `12/30`, CVC `123`, postcode `SW1A 1AA`
2. Click "Process Payment"
3. **Expected:**
   - Spinner shows on button
   - AJAX calls `guesty_create_booking_reservation`
   - Success panel appears with confirmation code

**Verify in Guesty:**
- Reservation appears in the Reservations list
- Status: Confirmed
- Guest is linked
- Payment method is attached to the guest

**Verify in Stripe:**
- Payment method `pm_xxx` appears under the customer in Stripe dashboard
- (If charges are triggered) Payment appears in Stripe → Payments

---

### Step 5 — Coupon Code

1. Enter a coupon code in the sidebar coupon field
2. Click "Apply"
3. **Expected:** Total price updates, new quote ID is stored internally

To test failure: enter a random invalid code → "Invalid coupon code." message shown.

---

## 5. Error & Edge Case Matrix

| Scenario | Expected Behaviour |
|----------|--------------------|
| Token expired mid-session | API calls fail → user sees "Authentication failed" error inline |
| Guesty API unreachable | Error screen shown on page load; AJAX errors shown in form |
| Payment provider ID not found | Reservation created but `paymentStatus: pending`; success screen shows "Payment: Pending" |
| Stripe card declined | `error.message` from Stripe shown inline in Step 3 |
| Guest email already in Guesty | Upsert updates the guest — no duplicate created |
| User manually edits dates in URL | Date validation catches invalid/unavailable dates and shows error screen |
| User hits Back after booking | Previous URL still validates; if dates passed they see error screen |

---

## 6. Debugging

### Enable WordPress Debug Logging

Add to `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Logs are written to `wp-content/debug.log`. The plugin calls `guesty_log()` for all API errors.

### Check What Payment Provider Is Being Used

Add a temporary line to `instant-booking.php` (after the provider detection block):

```php
error_log('Payment Provider ID: ' . $payment_provider_id);
```

Then check `wp-content/debug.log`.

### Test Payment Provider Endpoint Manually

```bash
curl -X GET "https://open-api.guesty.com/v1/payment-providers/default" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

Expected response body:
```json
{
  "_id": "your_provider_id",
  "type": "STRIPE",
  "name": "...",
  "currency": "GBP"
}
```

### Test Quote Endpoint Manually

```bash
curl -X POST "https://open-api.guesty.com/v1/quotes" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "listingId": "YOUR_LISTING_ID",
    "checkInDateLocalized": "2026-05-01",
    "checkOutDateLocalized": "2026-05-05",
    "guestsCount": 2,
    "source": "manual"
  }'
```

A valid response will have `data.status === "valid"` and contain the `data._id` (quote ID).

---

## 7. Complete Data Flow Diagram

```
Browser (Guest)
│
│  1. Open /instant-booking/?listing_id=X&check_in=Y&check_out=Z&guest=N
│
├─► PHP (instant-booking.php)
│   ├─ Validate date format & logic (PHP DateTime)
│   ├─ POST /v1/quotes  ──────────────────────► Guesty API
│   │   └─ valid? → extract prices, quote_id
│   │   └─ error? → show "Dates Unavailable" screen (STOP)
│   ├─ GET /v1/listings/{id}  ────────────────► Guesty API
│   │   └─ read house rules, cancellationPolicy, paymentProviderId
│   ├─ GET /v1/payment-providers/default  ────► Guesty API (if no provider ID yet)
│   └─ Render booking form with GBK data injected
│
│  2. User fills Step 1 (Guest Details) and submits
│
├─► AJAX: guesty_create_booking_guest
│   └─ POST /v1/guests-crud  ────────────────► Guesty API
│       └─ returns guestId
│
│  3. User reads Step 2 (Rules & Policies) and continues
│
│  4. User enters card in Step 3 and clicks Pay
│       └─ stripe.createPaymentMethod() ─────► Stripe
│           └─ returns paymentMethod.id (pm_xxx)
│
├─► AJAX: guesty_create_booking_reservation
│   ├─ POST /v1/reservations  ───────────────► Guesty API
│   │   body includes quoteId to lock price
│   │   └─ returns reservationId + confirmationCode
│   └─ POST /v1/guests/{id}/payment-methods ► Guesty API
│       body includes stripePaymentMethodId, paymentProviderId, reservationId
│       └─ returns 200/201 on success
│
└─► Show success screen with confirmationCode
```

---

## 8. Common Issues & Solutions

| Issue | Cause | Fix |
|-------|-------|-----|
| Booking page returns 404 | Rewrite rules not flushed | Settings → Permalinks → Save Changes |
| "Authentication failed" on page load | Guesty API credentials wrong or expired | Re-enter Client ID/Secret in plugin settings |
| Payment provider not found | No Stripe account connected to Guesty | Connect Stripe in Guesty → Settings → Payment Providers |
| Stripe card element doesn't appear | Stripe Publishable Key not set | Enter `pk_test_…` in Booking Settings |
| "Failed to create reservation" | Quote expired (>15 min on page) | User should refresh the page and re-book |
| Payment shows "pending" in success screen | Provider ID resolution failed | Check debug.log; manually set provider ID in Booking Settings |
| Coupon code not applied | Coupon not configured in Guesty | Create the promotion in Guesty → Revenue → Promotions |
