<?php

return [
    // Cancellation is intentionally narrower than the Super Administrator
    // role. SolarNet has nominated one accountable billing owner.
    'invoice_cancellation_authorized_email' => env(
        'INVOICE_CANCELLATION_AUTHORIZED_EMAIL',
        'solarnet.connection@gmail.com'
    ),
];
