<?php

declare(strict_types=1);

namespace AmpacheMcp;

/**
 * @return array<string, string>
 */
function ampache_mcp_config(): array
{
    $configFile = dirname(__DIR__) . '/config.php';
    if (!is_file($configFile)) {
        return [];
    }

    $config = require $configFile;
    if (!is_array($config)) {
        return [];
    }

    $normalized = [];
    foreach ($config as $key => $value) {
        $normalized[(string)$key] = trim((string)$value);
    }

    return $normalized;
}

function ampache_mcp_config_value(array $config, string $key, string $default = ''): string
{
    return ($config[$key] ?? '') !== '' ? $config[$key] : $default;
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

function ampache_mcp_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function ampache_mcp_base64url_decode(string $value): string
{
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    if ($decoded === false) {
        throw new \InvalidArgumentException('Invalid base64url value.');
    }

    return $decoded;
}

function ampache_mcp_boot_ampache(string $ampacheRoot): void
{
    static $booted = false;
    static $bootedRoot = '';

    $ampacheRoot = rtrim($ampacheRoot, '/');
    if ($booted) {
        if ($bootedRoot !== $ampacheRoot) {
            throw new \RuntimeException('Ampache has already been booted from a different root.');
        }

        return;
    }

    if ($ampacheRoot === '' || !is_file($ampacheRoot . '/src/Config/Init.php')) {
        throw new \RuntimeException('ampache_root must point at a local Ampache install.');
    }

    if (!defined('NO_SESSION')) {
        define('NO_SESSION', '1');
    }
    if (!defined('OUTDATED_DATABASE_OK')) {
        define('OUTDATED_DATABASE_OK', 1);
    }

    ob_start();
    require $ampacheRoot . '/src/Config/Init.php';
    ob_end_clean();

    $booted = true;
    $bootedRoot = $ampacheRoot;
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
