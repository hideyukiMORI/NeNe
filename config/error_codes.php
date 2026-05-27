<?php

declare(strict_types=1);

return [
    'SESSION-CLOSED' => [
        'message' => 'Session timeout. Please log in again.',
        'httpStatus' => 401,
    ],
    'LOGIN-FAILED' => [
        'message' => 'Wrong user ID or user PASS',
        'httpStatus' => 401,
    ],
    'FORBIDDEN' => [
        'message' => 'You do not have permission to perform this action.',
        'httpStatus' => 403,
    ],
    'CSRF-TOKEN-INVALID' => [
        'message' => 'Invalid CSRF token.',
        'httpStatus' => 403,
    ],
    'METHOD-NOT-ALLOWED' => [
        'message' => 'The HTTP method is not allowed for this endpoint.',
        'httpStatus' => 405,
    ],
    'NOT-FOUND' => [
        'message' => 'The requested resource was not found.',
        'httpStatus' => 404,
    ],
    'INTERNAL-ERROR' => [
        'message' => 'An unexpected internal error occurred.',
        'httpStatus' => 500,
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
    'UPLOAD-FILE-REQUIRED' => [
        'message' => 'Upload file is required.',
        'httpStatus' => 400,
    ],
    'UPLOAD-TOO-LARGE' => [
        'message' => 'Upload exceeds size limit.',
        'httpStatus' => 413,
    ],
    'UPLOAD-MIME-REJECTED' => [
        'message' => 'Upload mime type is not allowed.',
        'httpStatus' => 415,
    ],
];
