# org.uschess.square

Square payment processor extension for CiviCRM.

- **Extension key:** `org.uschess.square`
- **Version:** 1.1.0 (beta)
- **CiviCRM compatibility:** 6.16+
- **License:** AGPL-3.0-or-later
- **Square API version:** 2025-01-15

---

## Features

- One-time card payments via Square Payments API (`/v2/payments`)
- Recurring contributions via Square Subscriptions API (`/v2/subscriptions`)
- Refunds via Square Refunds API (`/v2/refunds`)
- Subscription cancellation and amount updates synced to Square
- Square Web Payments SDK for browser-side card tokenization — card details never pass through CiviCRM
- Card-on-file support through CiviCRM PaymentToken
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

Configure the Square webhook endpoint as
`https://your-site.org/civicrm/payment/ipn/{processor_id}`. It validates the
`X-Square-Hmacsha256-Signature` header before the event is queued. The queue
worker processes events asynchronously and retries failures.

### Handled events

| Event | Action |
|---|---|
| `subscription.created` | Syncs subscription status to `ContributionRecur` |
| `subscription.updated` | Syncs subscription status/amount to `ContributionRecur` |
| `subscription.canceled` | Marks `ContributionRecur` as Cancelled |
| `invoice.created` | Creates a Pending contribution for the upcoming invoice |
| `invoice.payment_made` | Reconciles the paid invoice |
| `invoice.payment_failed` | Reconciles the failed invoice |
| `payment.updated` | Reconciles an existing contribution |
| `refund.created` | Reconciles a refund |

Webhook deduplication uses CiviCRM's `civicrm_paymentprocessor_webhook` queue
and Square's globally unique event ID. Do not log webhook signatures, access
tokens, card nonces, or unredacted Square responses.

---

## Custom Data

On install, a `square_data` custom group is created for the **Contact** entity with these fields:

| Field | Description |
|---|---|
| `square_customer_id` | Square customer ID linked to this contact |
| `square_card_id` | Square card-on-file ID for recurring payments |

---

---

## JavaScript Integration

`js/square.js` provides full browser-side integration with the Square Web Payments SDK. It supports:

- CiviCRM native contribution pages and event registration forms
- Drupal Webform (webform_civicrm module) billing blocks, including AJAX reloads
- Backend contribution/event forms

Key globals:
- `CRM.squarePayment` — Square's namespaced integration state
- `CRM.vars.orgUschessSquare` — processor settings (Application ID, Location ID, sandbox flag)
- `window.civicrmSquareHandleReload` — reinitializes the card element when the billing block is replaced via AJAX

The card element mounts into `#square-card-container`. Tokenization happens on form submit; the resulting nonce is written to a hidden `square_payment_token` field for PHP to read.

---

---

## CiviCRM Hooks

| Hook | Purpose |
|---|---|
| `hook_civicrm_enable` | Enforces `billing_mode = 1` on all Square payment processor instances |
| `hook_civicrm_install` | Installs managed extension configuration |

---

## Database Tables (created on install)

**`civicrm_paymentprocessor_webhook`** — CiviCRM's webhook queue; it retains
deduplication, status and retry information.

---

## File Structure

```
CRM/
  Core/Payment/
    Square.php          Payment processor class (payments, subscriptions, refunds, webhooks)
    SquareIPN.php       Webhook event router and processor
js/
  square.js             Browser-side Square Web Payments SDK integration
managed/
  PaymentProcessorType.mgd.php  Registers the Square payment processor type
templates/
  CRM/Core/Payment/Square/Card.tpl  Card container HTML injected into billing block
xml/Menu/square.xml     Settings route definition
square.php              Extension bootstrap and additional hooks
```
