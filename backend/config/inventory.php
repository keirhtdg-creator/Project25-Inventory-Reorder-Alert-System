<?php

return [
    'aging_days' => (int) env('INVENTORY_AGING_DAYS', 7),
    'timezone' => env('INVENTORY_TIMEZONE', 'Asia/Manila'),
    'supervisor_owner' => env('INVENTORY_SUPERVISOR_OWNER'),
    'automation_max_attempts' => (int) env('INVENTORY_AUTOMATION_MAX_ATTEMPTS', 3),
    'automation_retry_delays' => [60, 300],
];
