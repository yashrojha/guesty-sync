# Instant Booking — Payment Implementation Plan

## Overview

This document covers how payment is processed on the instant-booking page using the **GuestyPay SDK** (the same approach used by sleepoverstays.co.uk), what the Guesty API expects at each step, and what is required to make real charges work reliably.

---

## What sleepoverstays.co.uk Uses (and we now match)

The reference site renders payment via:
```
https://pay.guesty.com?providerId=67d991f6aa23cc000e786923&version=v1
```

This is the **GuestyPay Tokenization JS SDK** — a hosted, PCI-compliant iframe that renders inside your page. Guesty's own docs recommend this as the **only supported method going forward**.

We now load it identically via:
```html
<script src="https://pay.guesty.com/tokenization/v1/init.js"></script>
```

This makes `window.guestyTokenization` available globally with `render()`, `submit()`, `validate()`, and `destroy()` methods.

---

## Full Booking Flow (End-to-End)

```
Guest selects dates on property page
        │
        ▼
instant-booking.php loads (server-side)
   ├─ Calendar availability pre-check   (guesty_check_availability)
   ├─ POST /v1/quotes                   → quote_id + price breakdown (locked)
   ├─ GET  /v1/listings/{id}            → house rules + paymentProviderId (Tier 2)
   └─ GET  /v1/payment-providers/default → fallback if listing has no provider (Tier 3)
        │
        ▼
[STEP 1] Guest fills in: first name, last name, email, phone
   └─ AJAX → guesty_create_booking_guest
         └─ POST /v1/guests-crud  →  guestId  (creates or updates guest record)
        │
        ▼
[STEP 2] Guest reads & agrees to house rules / cancellation policy
        │
        ▼
[STEP 3] GuestyPay iframe renders inside #gbk-payment-container
   └─ window.guestyTokenization.render({ containerId, providerId, amount, currency })
   └─ onStatusChange fires → "Process Payment" button enables when form is valid
   └─ Guest enters card number, expiry, CVC, billing address
   └─ Guest clicks "Process Payment"
         │
         ├─ window.guestyTokenization.submit()
         │       └─ Card tokenized inside the iframe (never touches our server)
         │       └─ 3DS handled automatically by GuestyPay (redirect if needed)
         │       └─ Returns  { _id: "token-uuid" }
         │
         └─ AJAX → guesty_create_booking_reservation
               ├─ POST /v1/reservations                      →  reservationId + confirmationCode
               └─ POST /v1/guests/{guestId}/payment-methods  →  attaches token to reservation
        │
        ▼
Success screen shown with confirmationCode
Guesty automation rules handle the actual charge
```

---

## Guesty API Calls in Detail

### 1. Create / Upsert Guest — `POST /v1/guests-crud`

**Handler:** `guesty_create_booking_guest_handler()` in `includes/ajax.php`

```json
{
  "firstName": "Jane",
  "lastName":  "Smith",
  "email":     "jane@example.com",
  "phone":     "+441234567890"
}
```

**Response key field:** `_id` — becomes `guestId` for all subsequent calls.

> Guesty auto-matches on email. A returning guest's existing record is updated and its `_id` returned. No duplicates.

---

### 2. Create Reservation — `POST /v1/reservations`

**Handler:** `guesty_create_booking_reservation_handler()` in `includes/ajax.php`

```json
{
  "listingId":             "...",
  "checkInDateLocalized":  "2026-04-10",
  "checkOutDateLocalized": "2026-04-15",
  "guestsCount":           2,
  "guestId":               "<guestId from Step 1>",
  "source":                "direct",
  "status":                "confirmed",
  "quoteId":               "<quote_id from page load>"
}
```

`quoteId` locks in the price shown to the guest at page-load time — price-safe even if rates change while they fill in the form.

**Response:** `_id` (reservationId) + `confirmationCode`.

---

### 3. Attach GuestyPay Token — `POST /v1/guests/{guestId}/payment-methods`

**Handler:** Same AJAX call, runs immediately after reservation is created.

```json
{
  "_id":               "849c9df2-a326-4cf6-9106-ded9b893d7e6",
  "paymentProviderId": "<GuestyPay provider _id>",
  "reservationId":     "<reservationId from step above>"
}
```

Key points:
- `_id` — the GuestyPay tokenization ID returned by `guestyTokenization.submit()`. Card data never touches our server.
- `paymentProviderId` — resolved via 3-tier fallback (see below).
- `reservationId` — **must be included** to enable Guesty's payment automation rules.

---

## Payment Provider ID — Resolution Order

| Tier | Source | How |
|------|--------|-----|
| 1 | WordPress admin setting | `get_option('guesty_stripe_payment_provider_id')` set in Guesty Sync → Settings |
| 2 | Listing object from Guesty | `GET /v1/listings/{id}` → `paymentProviderId` |
| 3 | Account default | `GET /v1/payment-providers/default` |

The resolved `paymentProviderId` is:
- Passed to JS (`GBK.paymentProviderId`)
- Used in `guestyTokenization.render()` so the iframe knows which processor to use
- Sent back to PHP AJAX to attach the payment method

If none of the three tiers resolves a provider, the payment form won't render. **Ensure Tier 1 (admin setting) or the account default is configured.**

---

## GuestyPay SDK — How the Iframe Works

The SDK is loaded as a plain script tag (not bundled — required for PCI compliance):
```html
<script src="https://pay.guesty.com/tokenization/v1/init.js"></script>
```

This exposes `window.guestyTokenization` with the v1 interface:

| Method | Purpose |
|--------|---------|
| `render(options)` | Injects the payment iframe into the specified container div |
| `submit()` | Tokenizes the card; returns `Promise<{ _id: string }>` |
| `validate()` | Triggers form validation visually |
| `destroy()` | Removes the iframe |

### `render()` options used

```js
window.guestyTokenization.render({
    containerId:    'gbk-payment-container',  // div to inject iframe into
    providerId:     GBK.paymentProviderId,    // GuestyPay provider _id
    amount:         GBK.totalPrice,           // used for 3DS challenge amount
    currency:       GBK.currency,             // e.g. "GBP"
    onStatusChange: function(isValid) {       // enable/disable pay button
        payBtn.disabled = !isValid;
    },
});
```

### `submit()` flow
1. Guest fills in card details inside the iframe.
2. `onStatusChange(true)` fires — "Process Payment" button becomes enabled.
3. Guest clicks the button → JS calls `await window.guestyTokenization.submit()`.
4. GuestyPay tokenizes the card, runs 3DS if required (redirect handled internally by the SDK).
5. Returns `{ _id: "token-uuid" }`.
6. We send this `_id` to our PHP backend, which attaches it to the reservation.

---

## What Happens After the Token is Attached

Attaching a payment method **does not immediately charge the card**. Guesty uses **payment automation rules** configured in your Guesty account to determine when to charge:

- Go to: Guesty Dashboard → Settings → Payment Processing → Payment Automation
- Example rule: "Charge 100% on reservation confirmation"

If no automation rules are set, the payment sits pending and must be charged manually from the Guesty dashboard.

> **Action required:** Confirm payment automation rules are configured in your Guesty account.

---

## 3D Secure (3DS)

3DS is **fully handled by the GuestyPay SDK internally**. The iframe manages the authentication redirect using the `amount` and `currency` you pass to `render()`. You do not need to write any 3DS redirect logic. This is one of the key reasons to use the SDK over raw API calls.

---

## What Changed From the Previous Implementation

| | Before | After |
|---|---|---|
| Payment form | Stripe.js `Elements` card element | GuestyPay SDK iframe |
| Script loaded | `https://js.stripe.com/v3/` | `https://pay.guesty.com/tokenization/v1/init.js` |
| Tokenization | `stripe.createPaymentMethod()` → `pm_xxx` | `guestyTokenization.submit()` → `{ _id }` |
| Token field sent to PHP | `stripeToken` (POST key) | `guestyToken` (POST key) |
| Guesty API field | `stripeCardToken` (was `stripePaymentMethodId` — wrong) | `_id` |
| 3DS handling | Not implemented | Handled by SDK |
| Button state | Always enabled | Disabled until form valid (via `onStatusChange`) |
| `$stripe_key` in PHP | Required | Removed — `$payment_provider_id` used instead |

---

## Files Changed

| File | What changed |
|------|-------------|
| `templates/instant-booking.php` | Removed Stripe.js + `stripe`/`cardElement` vars; added GuestyPay SDK + `initGuestyPayForm()`; pay button uses `guestyTokenization.submit()` |
| `includes/ajax.php` | Renamed `stripeToken` → `guestyToken`, `$stripe_token` → `$guesty_token`; pay body now uses `_id` instead of `stripeCardToken` |
| `PAYMENT_IMPLEMENTATION.md` | This file — updated to reflect final approach |

---

## What You Still Need To Do

- [ ] **Verify `paymentProviderId` resolves correctly** — open a property's booking page, check browser console for the `GBK.paymentProviderId` value. It must be a non-empty string like `67d991f6aa23cc000e786923`.
- [ ] **Configure payment automation rules in Guesty** — so cards are charged automatically on confirmation.
- [ ] **End-to-end test** — use a real card in a live/staging Guesty account (GuestyPay has no sandbox).

---

## Step-by-Step Test Sequence

1. Open a property page, pick available dates, click "Book Now".
2. On the instant-booking page, open browser DevTools → Console.
3. Confirm `GBK.paymentProviderId` is populated (not empty).
4. Fill in Step 1 (guest details) → click Continue.
5. Check Guesty dashboard to confirm guest record was created / updated.
6. Agree to policies in Step 2 → click Continue.
7. The GuestyPay iframe should render inside the payment step with card fields.
8. The "Process Payment" button should be **disabled** until all fields are filled.
9. Fill in a valid card — button enables.
10. Click "Process Payment".
11. If 3DS is required, GuestyPay handles the redirect automatically.
12. On success, the booking confirmation screen appears with `confirmationCode`.
13. In Guesty dashboard: verify reservation exists with status `confirmed`, payment method attached, and automation rule triggered a charge.

---

## Relevant API & SDK References

- [GuestyPay Tokenization JS SDK — NPM](https://www.npmjs.com/package/@guestyorg/tokenization-js)
- [GuestyPay Tokenization JS SDK — GitHub Wiki](https://github.com/guestyorg/tokenization-js/wiki)
- [Tokenizing Payment Methods (Guesty Docs)](https://open-api-docs.guesty.com/docs/tokenizing-payment-methods)
- [Create Guest and Payment Method](https://open-api-docs.guesty.com/docs/create-guest-and-payment-method)
- [Guest Payment Methods API Reference](https://open-api-docs.guesty.com/reference/post_guests-id-payment-methods)
