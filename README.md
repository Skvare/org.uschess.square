# org.uschess.square

Square payment processor skeleton for CiviCRM.

This extension:

- Registers a `Square` payment processor type via a managed entity.
- Provides a `CRM_Core_Payment_Square` class which performs basic configuration
  checks and declares support for recurring payments.

The `doPayment()` method currently throws an exception so that it cannot be used
in production until the full Square API integration (Payments, Customers,
Cards, Subscriptions) is implemented.
