<?php

declare(strict_types=1);

namespace AmpacheMcp;

function ampache_mcp_env(string $key, string $default = ''): string
{
    $value = getenv($key);

    return ($value === false) ? $default : trim((string)$value);
}

function ampache_mcp_json_response(array $payload, int $status = 200, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * @return array<string, string>
 */
function ampache_mcp_request_headers(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        return is_array($headers) ? array_map('strval', $headers) : [];
    }

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (!str_starts_with((string)$key, 'HTTP_')) {
            continue;
        }

        $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string)$key, 5)))));
        $headers[$name] = (string)$value;
    }

    return $headers;
}
