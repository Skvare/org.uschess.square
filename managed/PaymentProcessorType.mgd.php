<?php

return [
  [
    'name'   => 'SquarePaymentProcessor',
    'entity' => 'payment_processor_type',
    'module' => 'org.uschess.square',
    'update' => 'always',
    'cleanup' => 'never',
    'params' => [
      'version'     => 3,
      'title'       => 'Square',
      'name'        => 'Square',
      'description' => 'Square payment processor for US Chess',

      // This ties to CRM_Core_Payment_Square in CRM/Core/Payment/Square.php
      'class_name'  => 'Payment_Square',

      // Admin form labels (live credentials)
      'user_name_label' => 'Square Application ID',
      'password_label'  => 'Square Access Token',
      'signature_label' => 'Square Location ID',
      'subject_label'   => 'Square Webhook Signature Key',

      // Admin form labels (test credentials)
      'test_user_name_label' => 'Square Application ID (Test)',
      'test_password_label'  => 'Square Access Token (Test)',
      'test_signature_label' => 'Square Location ID (Test)',
      'test_subject_label'   => 'Square Webhook Signature Key (Test)',

      // Base URLs – we mostly use the SDK, but Civi still likes these sane defaults
      // LIVE
      'url_site_default' => 'https://connect.squareup.com',
      'url_api_default'  => 'https://connect.squareup.com',

      // TEST (sandbox)
      'url_site_test_default' => 'https://connect.squareupsandbox.com',
      'url_api_test_default'  => 'https://connect.squareupsandbox.com',

      // On-site card entry (we use Web Payments SDK)
      // 1 = onsite, 4 = offsite/redirect
      'billing_mode' => 1,

      // 1 = credit card
      'payment_type' => 1,

      // Capabilities flags
      'is_recur'        => 1,   // we support recurring
      'supports_refund' => 1,   // we support refunds
      'is_test'         => 1,   // we support test mode
    ],
  ],
];