<?php

declare(strict_types=1);

namespace LessorMQ;

use DateTimeInterface;

/**
 * Settings a publisher may pass; the queue's limits apply.
 */
final class PublishOptions
{
    /**
     * @param array<string, string> $headers     free string metadata, passed through
     * @param string|null           $dedupKey    while a message with this key is open, publishing returns it
     * @param int|null              $maxAttempts overrides the queue's max_attempts, capped by the queue
     */
    public function __construct(
        public readonly array $headers = [],
        public readonly ?string $dedupKey = null,
        public readonly ?int $delaySeconds = null,
        public readonly ?DateTimeInterface $availableAt = null,
        public readonly ?int $maxAttempts = null,
    ) {
    }
}
