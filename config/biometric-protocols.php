<?php

return [
    'default' => 'attendance_push',

    'profiles' => [
        'attendance_push' => [
            'label' => 'Attendance PUSH',
            'inventory_mode' => 'detailed',
            'inventory_command' => 'DATA QUERY USERINFO',
        ],
        'senseface_push' => [
            'label' => 'SenseFace PUSH',
            'inventory_mode' => 'aggregate_info',
            'inventory_command' => 'DATA QUERY USERINFO',
            'device_names' => ['senseface'],
            'firmware_prefixes' => ['zam70'],
        ],
        'legacy_attendance_aggregate' => [
            'label' => 'Attendance PUSH (INFO)',
            'inventory_mode' => 'aggregate_info',
            'inventory_command' => 'DATA QUERY USERINFO',
            'firmware_prefixes' => ['ver 8.'],
        ],
    ],
];
