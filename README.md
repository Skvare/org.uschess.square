# org.uschess.square

Square payment processor extension for CiviCRM.

- **Extension key:** `org.uschess.square`
- **Version:** 1.2.0 (beta)
- **CiviCRM compatibility:** 6.16+
- **License:** AGPL-3.0-or-later
- **Square API version:** 2025-01-15
- **Requires:** `mjwshared`, `civi_contribute` (see `info.xml`)

---

## Installation

This extension ships its `vendor/` directory (and `composer.lock`) committed to the
repository, so no `composer install` step is required after cloning/downloading —
just enable the extension as usual (**Administer → System Settings → Extensions**).

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

## Card & Customer Storage

Square identifiers are **not** stored as Contact custom fields. Both are
scoped per payment processor, since live and sandbox (or multiple Square
merchant accounts) are entirely separate environments with their own
customer/card records:

- **Cards** are stored as standard CiviCRM `PaymentToken` records
  (`civicrm_payment_token`), one per card, linked from
  `civicrm_contribution_recur.payment_token_id`. This lets a contact have
  more than one card on file across different recurring contributions,
  and lets other CiviCRM UI/tooling that understands `PaymentToken` work
  with them normally.
- **Square customer IDs** are stored in `square_customer_map`, an
  extension-owned table keyed by `(contact_id, payment_processor_id)` —
  see [Database Tables](#database-tables) below.

Earlier versions of this extension stored both as a `square_data` custom
field group on the Contact entity. `CRM_Square_Upgrader::upgrade_1000()`
migrates that legacy data into `square_customer_map` automatically **only**
when a contact's stored customer ID can be attributed to exactly one
configured Square processor. If more than one Square processor is
configured, the legacy field never recorded which one a given value
belongs to, so migration is skipped for that data (never guessed — live is
never assumed) and it's logged via `Civi::log()->warning()` for manual
reconciliation. The `square_data` group itself is only removed once
nothing ambiguous remains.

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
| `hook_civicrm_config` | Standard civix bootstrap |
| `hook_civicrm_install` | Standard civix install (schema/table creation is handled separately by `CRM_Square_Upgrader`, not this hook) |
| `hook_civicrm_uninstall` | Defensive cleanup of the legacy `square_data` custom field group, if it's still present |
| `hook_civicrm_enable` | Enforces `billing_mode = 1` on all Square payment processor instances |
| `hook_civicrm_managed` | Wires up `managed/PaymentProcessorType.mgd.php` (registers the Square payment processor type) |
| `hook_civicrm_navigationMenu` | Adds "Square Settings" under Administer → System Settings |

---

## Database Tables

| Table | Owner | Purpose |
|---|---|---|
| `square_customer_map` | This extension (`CRM_Square_Upgrader`) | Maps `(contact_id, payment_processor_id)` → Square customer ID. Created on install; dropped on uninstall. |
| `civicrm_payment_token` | CiviCRM core | Stores Square card-on-file references (one row per card), linked from `civicrm_contribution_recur.payment_token_id`. Not owned by this extension — never dropped on uninstall. |
| `civicrm_paymentprocessor_webhook` | CiviCRM core | Webhook queue; retains deduplication, status and retry information. Not owned by this extension. |

---

## File Structure

```
CRM/
  Core/Payment/
    Square.php               Payment processor class (payments, subscriptions, refunds, webhooks)
    SquareIPN.php            Webhook event router and processor
    SquareDebugLogger.php    Opt-in verbose debug logging, gated by the square_ipn_debug_logging setting
  Square/
    Form/Settings.php        Administer > System Settings > Square Settings (debug logging toggle)
    Upgrader.php             Creates/backfills/drops the square_customer_map table (see Database Tables)
js/
  square.js                  Browser-side Square Web Payments SDK integration
managed/
  PaymentProcessorType.mgd.php  Registers the Square payment processor type
settings/
  Square.setting.php         Declares the square_ipn_debug_logging setting
templates/
  CRM/Core/Payment/Square/Card.tpl  Card container HTML injected into billing block
  CRM/Square/Form/Settings.tpl      Settings form markup
xml/Menu/square.xml          Route: civicrm/admin/setting/square -> CRM_Square_Form_Settings
square.php                   Extension bootstrap and hooks (config/install/uninstall/enable/managed/navigationMenu)
```
