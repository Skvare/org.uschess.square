# org.uschess.square

Square payment processor extension for CiviCRM.

- **Extension key:** `org.uschess.square`
- **Version:** 1.0.0 (alpha)
- **CiviCRM compatibility:** 6.8+
- **License:** AGPL-3.0
- **Square API version:** 2025-01-15

---

## Features

- One-time card payments via Square Payments API (`/v2/payments`)
- Recurring contributions via Square Subscriptions API (`/v2/subscriptions`)
- Refunds via Square Refunds API (`/v2/refunds`)
- Subscription cancellation and amount updates synced to Square
- Square Web Payments SDK for browser-side card tokenization — card details never pass through CiviCRM
- Card-on-file support (card tokens stored per contact)
- Square customer creation and deduplication (by email and `reference_id`)
- Webhook event handling with deduplication and delivery logging
- Supports sandbox (test) and production environments

---

## Payment Processor Configuration

Navigate to **Administer → System Settings → Payment Processors** and create a new processor of type **Square**.

| Field | Description |
|---|---|
| Square Application ID | Found under Developer Dashboard → Your Application → Credentials |
| Square Access Token | Live or sandbox access token |
| Square Location ID | Found under Locations in your Square Dashboard |
| Square Webhook Signature Key | Used to validate incoming webhook events (HMAC-SHA256) |

Separate sandbox credentials are supported for test mode. The processor automatically sets `billing_mode = 1` (on-site) on enable to ensure compatibility with Drupal Webform CiviCRM.

---

## Recurring Payments — Supported Cadences

| Cadence | Interval |
|---|---|
| DAILY | Every day |
| WEEKLY | Every week |
| EVERY_TWO_WEEKS | Every 2 weeks |
| MONTHLY | Every month |
| EVERY_TWO_MONTHS | Every 2 months |
| QUARTERLY | Every 3 months |
| EVERY_SIX_MONTHS | Every 6 months |
| ANNUAL | Every year |

Recurring payments use the Square Catalog API to create subscription plans and plan variations on demand, then create a Square Subscription linked to a card-on-file. An initial charge is made immediately at subscription creation; subsequent charges are handled by Square and reported back via webhooks.

---

## Webhook Events

Configure your Square webhook endpoint in the Square Developer Dashboard. Two URLs are available:

- **Standard IPN URL (preferred):** `https://your-site.org/civicrm/payment/ipn/{processor_id}`
- **Legacy URL:** `https://your-site.org/civicrm/square/webhook`

Both URLs validate the `X-Square-Signature` header (HMAC-SHA256 using the Webhook Signature Key) before processing.

### Handled events

| Event | Action |
|---|---|
| `subscription.created` | Syncs subscription status to `ContributionRecur` |
| `subscription.updated` | Syncs subscription status/amount to `ContributionRecur` |
| `subscription.canceled` | Marks `ContributionRecur` as Cancelled |
| `invoice.created` | Creates a Pending contribution for the upcoming invoice |
| `invoice.payment_made` | Creates a Completed contribution for the paid invoice |
| `invoice.payment_failed` | Marks contribution as Failed |
| `payment.updated` | Syncs payment status to existing contribution |
| `refund.created` | Marks contribution as Refunded |

Webhook deduplication is performed via the `civicrm_square_webhook_event` database table. All delivery attempts are logged to `civicrm_square_webhook_delivery`.

---

## Custom Data

On install, a `square_data` custom group is created for the **Contact** entity with these fields:

| Field | Description |
|---|---|
| `square_customer_id` | Square customer ID linked to this contact |
| `square_card_id` | Square card-on-file ID for recurring payments |

---

## Contact Tab

A **Square Tokens** tab is added to the Contact Summary page (route: `civicrm/square/tokens`). It displays the contact's Square customer ID, stored card ID, and linked recurring contribution records.

---

## JavaScript Integration

`js/square.js` provides full browser-side integration with the Square Web Payments SDK. It supports:

- CiviCRM native contribution pages and event registration forms
- Drupal Webform (webform_civicrm module) billing blocks, including AJAX reloads
- Backend contribution/event forms

Key globals:
- `CRM.squarePayment` — shared payment utility object (form detection, validation, submit handling)
- `CRM.vars.orgUschessSquare` — processor settings (Application ID, Location ID, sandbox flag)
- `window.civicrmSquareHandleReload` — reinitializes the card element when the billing block is replaced via AJAX

The card element mounts into `#square-card-container`. Tokenization happens on form submit; the resulting nonce is written to a hidden `square_payment_token` field for PHP to read.

---

## AJAX Token Endpoint

`civicrm/square/token` (POST) exchanges a Web Payments SDK nonce for a persistent Square card-on-file ID. Parameters:

| Parameter | Description |
|---|---|
| `token` | Square nonce from the Web Payments SDK |
| `processor_id` | CiviCRM payment processor ID |
| `contact_id` | CiviCRM contact ID |

Returns `{"success": true, "civi_token": "<card_id>"}` or `{"success": false, "error": "..."}`.

---

## CiviCRM Hooks

| Hook | Purpose |
|---|---|
| `hook_civicrm_buildForm` | Injects hidden `square_payment_token` field on native contribution and event registration forms |
| `hook_civicrm_post` (ContributionRecur) | Cancels the Square subscription when a recurring contribution is set to Cancelled; updates the subscription amount when the amount changes |
| `hook_civicrm_enable` | Enforces `billing_mode = 1` on all Square payment processor instances |
| `hook_civicrm_install` | Creates `square_data` custom fields and webhook tracking tables |
| `hook_civicrm_tabs` | Adds the Square Tokens tab to the Contact Summary |

---

## Database Tables (created on install)

**`civicrm_square_webhook_event`** — deduplication table; prevents the same Square event from being processed twice.

**`civicrm_square_webhook_delivery`** — audit log of every webhook received, with event type, status message, and HTTP response code.

---

## File Structure

```
CRM/
  Core/Payment/
    Square.php          Payment processor class (payments, subscriptions, refunds, webhooks)
    SquareIPN.php       Webhook event router and processor
  UschessSquare/
    Ajax/SquareToken.php  AJAX endpoint: nonce → card-on-file ID
    Page/Tokens.php       Contact tab page controller
    Webhook.php           Legacy webhook handler (delegates to Square.php)
js/
  square.js             Browser-side Square Web Payments SDK integration
managed/
  PaymentProcessorType.mgd.php  Registers the Square payment processor type
templates/
  CRM/Core/Payment/Square/Card.tpl  Card container HTML injected into billing block
xml/Menu/square.xml     Route definitions
hooks.php               CiviCRM hook implementations
square.php              Extension bootstrap and additional hooks
```
