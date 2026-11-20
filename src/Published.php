<?php

declare(strict_types=1);

namespace LessorMQ;

use DateTimeImmutable;

/**
 * The answer to a publish: the message, or the open one with the same dedup_key.
 */
final class Published
{
    public function __construct(
        public readonly string $id,
        public readonly string $queue,
        public readonly string $status,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $availableAt,
        public readonly bool $duplicate,
    ) {
    }

    /**
     * @param array<string, mixed> $data PublishResponse from the API
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['queue'],
            (string) $data['status'],
            Time::parse((string) $data['created_at']),
            Time::parse((string) $data['available_at']),
            (bool) $data['duplicate'],
        );
    }
}
