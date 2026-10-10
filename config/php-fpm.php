<?php

return [
    // Status listeners are reachable only through the internal container networks.
    'status' => [
        'host' => 'app',
        'ports' => [
            'www' => 9101,
            'webhook' => 9102,
        ],
    ],
];
