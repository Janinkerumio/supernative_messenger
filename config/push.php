<?php

return [
    // Firebase project ID — Firebase Console > Project Settings.
    'project_id' => env('FCM_PROJECT_ID'),

    // Absolute path to the service-account JSON used to sign FCM v1 requests.
    // Keep this OFF the device build and out of version control.
    'credentials' => env('FIREBASE_CREDENTIALS'),

    /*
    |--------------------------------------------------------------------------
    | Allowed background events (security)
    |--------------------------------------------------------------------------
    | The FCM `data.event` key names the event class dispatched on the device in
    | core's ephemeral runtime. When this list is non-empty, `native:push:dispatch`
    | will ONLY instantiate classes named here.
    |
    | SuperNative's server sends notification-style pushes only (no data.event),
    | so nothing is dispatched in the ephemeral runtime and this stays empty.
    | If background data sync is added later, name the allowed class(es) here.
    */
    'allowed_events' => [
        // \Lumi\NativePush\Events\PushNotificationReceived::class,
    ],
];
