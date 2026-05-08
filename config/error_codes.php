<?php

declare(strict_types=1);

return [
    'SESSION-CLOSED' => [
        'message' => 'Session timeout. Please log in again.',
        'httpStatus' => 200
    ],
    'LOGIN-FAILED' => [
        'message' => 'Wrong user ID or user PASS',
        'httpStatus' => 200
    ],
    'TODO-ID-REQUIRED' => [
        'message' => 'TODO id is required.',
        'httpStatus' => 400
    ],
    'TODO-NOT-FOUND' => [
        'message' => 'TODO item was not found.',
        'httpStatus' => 404
    ],
    'TODO-TITLE-REQUIRED' => [
        'message' => 'TODO title is required.',
        'httpStatus' => 400
    ]
];
