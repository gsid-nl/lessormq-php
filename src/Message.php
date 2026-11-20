<?php

declare(strict_types=1);

namespace LessorMQ;

use DateTimeImmutable;

/**
 * A delivered message: what a handler runs.
 */
final class Message
{
    /**
     * @param mixed                 $body    the JSON value as published, objects decoded as arrays
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $id,
        public readonly string $queue,
        public readonly mixed $body,
        public readonly array $headers,
        public readonly int $attempt,
        public readonly int $maxAttempts,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $data DeliveredMessage from the API
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['queue'],
            $data['body'] ?? null,
            is_array($data['headers'] ?? null) ? $data['headers'] : [],
            (int) $data['attempt'],
            (int) $data['max_attempts'],
            Time::parse((string) $data['created_at']),
        );
    }

    /**
     * Whether this attempt is the last one the queue allows.
     */
    public function isLastAttempt(): bool
    {
        return $this->attempt >= $this->maxAttempts;
    }
}
