<?php

declare(strict_types=1);

namespace AmpacheMcp;

interface PushSubscriptionStore
{
    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array;

    /**
     * @param array<string, mixed> $subscription
     * @return array<string, mixed>
     */
    public function save(array $subscription, string $userAgent = ''): array;

    public function deleteByEndpoint(string $endpoint): bool;

    public function deleteById(string $id): bool;
}
