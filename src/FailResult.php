<?php

declare(strict_types=1);

namespace LessorMQ;

use DateTimeImmutable;

/**
 * What the server did with a failed attempt. For logging: a client never acts on it.
 */
final class FailResult
{
    public const RETRYING = 'retrying';
    public const DEAD_LETTERED = 'dead_lettered';
    public const DISCARDED = 'discarded';

    public function __construct(
        public readonly string $outcome,
        public readonly ?DateTimeImmutable $availableAt,
        public readonly int $attempt,
        public readonly int $maxAttempts,
    ) {
    }

    /**
     * @param array<string, mixed> $data FailResponse from the API
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['outcome'],
            isset($data['available_at']) ? Time::parse((string) $data['available_at']) : null,
            (int) $data['attempt'],
            (int) $data['max_attempts'],
        );
    }
}
