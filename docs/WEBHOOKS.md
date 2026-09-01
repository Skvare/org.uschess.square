# Webhooks and operations

## Configure Square

For each CiviCRM Square processor, configure a Square webhook subscription:

```
https://your-site.example/civicrm/payment/ipn/{processor_id}
```

Replace `{processor_id}` with the CiviCRM payment processor ID. Use the Square
application and signature key for the same sandbox or production environment.
Subscribe to: `subscription.created`, `subscription.updated`,
`subscription.canceled`, `invoice.created`, `invoice.payment_made`,
`invoice.payment_failed`, `payment.updated`, and `refund.created`.

## Processing lifecycle

1. CiviCRM receives the request at the payment notification URL.
2. The extension validates the `X-Square-Hmacsha256-Signature` header against
   the raw body and CiviCRM notification URL.
3. Supported events are deduplicated by Square event ID and recorded in
   `civicrm_paymentprocessor_webhook`.
4. The queue record is immediately processed and marked `success` or `error`.
5. **Process Pending Webhooks** can retry records that need further processing.

Unsupported events are acknowledged but not processed.

## Troubleshooting

| Symptom | Check |
|---|---|
| HTTP 401 | Verify the webhook signature key and the exact configured URL. Scheme, hostname, path, proxy rewriting, and processor ID all matter. |
| HTTP 400 | Check that Square posted valid JSON. |
| No CiviCRM update | Inspect the queue record's event type, processor ID, status, and error message. |
| Repeated delivery | Resolve the queue-record error; duplicate event IDs are otherwise skipped. |

Do not log or share access tokens, signature keys, card tokens, raw webhook
bodies, or unredacted Square responses. Enable temporary verbose diagnostics at
**Administer → System Settings → Square Settings** and disable them afterwards.
