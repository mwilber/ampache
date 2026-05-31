<?php

declare(strict_types=1);

namespace AmpacheMcp;

final class JsonPushSubscriptionStore implements PushSubscriptionStore
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * @return array{path: string, directory: string, directoryExists: bool, directoryWritable: bool, fileExists: bool, fileWritable: bool, canWrite: bool, message: string}
     */
    public function diagnostics(): array
    {
        $directory = dirname($this->path);
        $directoryExists = is_dir($directory);
        $directoryWritable = $directoryExists && is_writable($directory);
        $fileExists = is_file($this->path);
        $fileWritable = $fileExists && is_writable($this->path);
        $canWrite = false;
        $message = '';

        try {
            if (!$directoryExists && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException('Directory does not exist and could not be created.');
            }

            if (!is_writable($directory)) {
                throw new \RuntimeException('Directory is not writable by PHP.');
            }

            if ($fileExists && !$fileWritable) {
                throw new \RuntimeException('Subscription file exists but is not writable by PHP.');
            }

            $probe = $this->path . '.write-test';
            if (file_put_contents($probe, gmdate('c') . "\n", LOCK_EX) === false) {
                throw new \RuntimeException('Unable to write a test file.');
            }
            unlink($probe);
            $canWrite = true;
            $message = 'Subscription storage is writable.';
        } catch (\Throwable $error) {
            $message = $error->getMessage();
        }

        return [
            'path' => $this->path,
            'directory' => $directory,
            'directoryExists' => is_dir($directory),
            'directoryWritable' => is_dir($directory) && is_writable($directory),
            'fileExists' => is_file($this->path),
            'fileWritable' => is_file($this->path) && is_writable($this->path),
            'canWrite' => $canWrite,
            'message' => $message,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $contents = file_get_contents($this->path);
        if ($contents === false || trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /**
     * @param array<string, mixed> $subscription
     * @return array<string, mixed>
     */
    public function save(array $subscription, string $userAgent = ''): array
    {
        $endpoint = trim((string)($subscription['endpoint'] ?? ''));
        $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
        if ($endpoint === '' || (string)($keys['p256dh'] ?? '') === '' || (string)($keys['auth'] ?? '') === '') {
            throw new \InvalidArgumentException('Subscription must include endpoint, keys.p256dh, and keys.auth.');
        }

        $now = gmdate('c');
        $id = self::idForEndpoint($endpoint);
        $record = [
            'id' => $id,
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => (string)$keys['p256dh'],
                'auth' => (string)$keys['auth'],
            ],
            'createdAt' => $now,
            'updatedAt' => $now,
            'userAgent' => $userAgent,
        ];

        $subscriptions = $this->all();
        $replaced = false;
        foreach ($subscriptions as $index => $existing) {
            if ((string)($existing['id'] ?? '') !== $id) {
                continue;
            }

            $record['createdAt'] = (string)($existing['createdAt'] ?? $now);
            $subscriptions[$index] = $record;
            $replaced = true;
            break;
        }

        if (!$replaced) {
            $subscriptions[] = $record;
        }

        $this->write($subscriptions);

        return $record;
    }

    public function deleteByEndpoint(string $endpoint): bool
    {
        return $this->deleteById(self::idForEndpoint($endpoint));
    }

    public function deleteById(string $id): bool
    {
        $subscriptions = $this->all();
        $next = array_values(array_filter(
            $subscriptions,
            static fn (array $subscription): bool => (string)($subscription['id'] ?? '') !== $id
        ));

        if (count($next) === count($subscriptions)) {
            return false;
        }

        $this->write($next);

        return true;
    }

    public static function idForEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /**
     * @param list<array<string, mixed>> $subscriptions
     */
    private function write(array $subscriptions): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create push subscription directory.');
        }
        if (!is_writable($directory)) {
            throw new \RuntimeException('Push subscription directory is not writable by PHP.');
        }

        $json = json_encode($subscriptions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($this->path, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write push subscription file.');
        }
    }
}
