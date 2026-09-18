<?php

return [
    // Push stays optional: an empty key pair disables sending, inbox notifications still work.
    'enabled' => filled(env('VAPID_PUBLIC_KEY')) && filled(env('VAPID_PRIVATE_KEY')),
    'subject' => env('VAPID_SUBJECT'),
    'public_key' => env('VAPID_PUBLIC_KEY'),
    'private_key' => env('VAPID_PRIVATE_KEY'),
];
