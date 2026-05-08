<?php

declare(strict_types=1);

return [
    'SESSION-CLOSED' => [
        'message' => 'Session timeout. Please log in again.',
        'httpStatus' => 200,
    ],
    'LOGIN-FAILED' => [
        'message' => 'Wrong user ID or user PASS',
        'httpStatus' => 200,
    ],
    'CSRF-TOKEN-INVALID' => [
        'message' => 'Invalid CSRF token.',
        'httpStatus' => 403,
    ],
    'METHOD-NOT-ALLOWED' => [
        'message' => 'The HTTP method is not allowed for this endpoint.',
        'httpStatus' => 405,
    ],
    'ROUTE-CONFLICT' => [
        'message' => 'Route configuration conflict.',
        'httpStatus' => 500,
    ],
    'TODO-ID-REQUIRED' => [
        'message' => 'TODO id is required.',
        'httpStatus' => 400,
    ],
    'TODO-NOT-FOUND' => [
        'message' => 'TODO item was not found.',
        'httpStatus' => 404,
    ],
    'TODO-TITLE-REQUIRED' => [
        'message' => 'TODO title is required.',
        'httpStatus' => 400,
    ],
];
