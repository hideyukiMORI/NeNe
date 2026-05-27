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
    'ACCOUNT-LOCKED' => [
        'message' => 'Account is locked due to too many failed login attempts.',
        'httpStatus' => 423,
    ],
    'BATCH-ITEM-FAILED' => [
        'message' => 'One or more batch items failed.',
        'httpStatus' => 422,
    ],
    'BATCH-TOO-LARGE' => [
        'message' => 'Batch request exceeds the maximum number of items.',
        'httpStatus' => 400,
    ],
    'CIRCUIT-OPEN' => [
        'message' => 'The downstream service is temporarily unavailable.',
        'httpStatus' => 503,
    ],
    'CONFLICT' => [
        'message' => 'A conflicting operation is already in progress.',
        'httpStatus' => 409,
    ],
    'FORBIDDEN' => [
        'message' => 'You do not have permission to perform this action.',
        'httpStatus' => 403,
    ],
    'INVALID-TRANSITION' => [
        'message' => 'The requested state transition is not allowed.',
        'httpStatus' => 409,
    ],
    'JWT-INVALID' => [
        'message' => 'The JWT token is invalid or expired.',
        'httpStatus' => 401,
    ],
    'PRECONDITION-FAILED' => [
        'message' => 'The resource was modified by another request. Fetch the latest version and retry.',
        'httpStatus' => 412,
    ],
    'PRECONDITION-REQUIRED' => [
        'message' => 'If-Match header is required for this operation.',
        'httpStatus' => 428,
    ],
    'RATE-LIMIT-EXCEEDED' => [
        'message' => 'Too many requests. Please try again later.',
        'httpStatus' => 429,
    ],
    'SIGNED-URL-EXPIRED' => [
        'message' => 'The signed URL has expired.',
        'httpStatus' => 410,
    ],
    'SIGNED-URL-INVALID' => [
        'message' => 'The signed URL is invalid.',
        'httpStatus' => 403,
    ],
    'TOKEN-ALREADY-USED' => [
        'message' => 'The reset token has already been used.',
        'httpStatus' => 409,
    ],
    'TOKEN-EXPIRED' => [
        'message' => 'The reset token has expired.',
        'httpStatus' => 410,
    ],
    'VALIDATION-FAILED' => [
        'message' => 'One or more input fields failed validation.',
        'httpStatus' => 422,
    ],
    'WEBHOOK-SIGNATURE-INVALID' => [
        'message' => 'Webhook signature is invalid or stale.',
        'httpStatus' => 401,
    ],
];
