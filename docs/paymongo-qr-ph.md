# PayMongo QR Ph

SolarNet uses PayMongo's Dynamic QR Ph Payment Intent flow. The QR shown in
the customer and collector screens is the `next_action.code.image_url` returned
by PayMongo after a `qrph` Payment Method is attached; SolarNet never encodes a
SolarNet URL into a replacement QR.

## Production configuration

Set these values in `deploy/.env` (never commit them):

```dotenv
PAYMONGO_BASE_URL=https://api.paymongo.com/v1
PAYMONGO_SECRET_KEY=sk_live_...
PAYMONGO_PUBLIC_KEY=pk_live_...
PAYMONGO_WEBHOOK_SECRET=whsk_...
```

Register this webhook in PayMongo:

```text
https://billing.solarnetconnection.com/api/v1/customer-portal/paymongo/webhook
```

Enable `payment.paid`, `payment.failed`, and `qrph.expired` events. Payment
confirmation is always re-read from PayMongo, and invoice/account ownership,
PHP currency, and the stored server-side amount are verified before a payment
is recorded. Repeated webhooks are safe because the stored PayMongo checkout
and payment IDs are locked and processed once.

The existing hosted GCash checkout remains available as a separate option.
Each QR Ph transaction is single-use and expires after the documented default
30 minutes; a new QR is created after expiry.
