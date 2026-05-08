<?php

declare(strict_types=1);

$openApiPath = dirname(__DIR__, 2) . '/docs/api/openapi.yaml';

if (!is_file($openApiPath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'OpenAPI document was not found.';
    exit;
}

header('Content-Type: application/yaml; charset=utf-8');
readfile($openApiPath);
