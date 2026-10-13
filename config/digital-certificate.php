<?php

return [
    // Certificate material must remain under storage/app, never public/.
    'certificate_path' => storage_path('app/certificates/certificate.pem'),
    'private_key_path' => storage_path('app/certificates/private.key'),
    'private_key_password' => env('DMS_CERTIFICATE_PRIVATE_KEY_PASSWORD', ''),
    'signature_image_path' => storage_path('app/certificates/9528111QW12SS31XASASASDSSYU9Q8EM3KDMTG.png'),
];
