<?php

return [
    // Production access is denied until explicitly allowlisted. Keep this server-side.
    'emails' => array_values(array_filter(array_map('trim', explode(',', env('ADMIN_EMAILS', ''))))),
];
