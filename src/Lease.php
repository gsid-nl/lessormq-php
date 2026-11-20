<?php

declare(strict_types=1);

namespace LessorMQ;

use DateTimeImmutable;

/**
 * A claimed message and the proof of holding it. Extend, ack, fail and release take the
 * lease; only its receipt is accepted, and only until leaseUntil().
 */
final class Lease
{
    public function __construct(
        public readonly string $receipt,
        private DateTimeImmutable $leaseUntil,
        public readonly Message $message,
    ) {
    }

    /**
     * @param array<string, mixed> $data Lease from the API
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['receipt'],
            Time::parse((string) $data['lease_until']),
            Message::fromArray($data['message']),
        );
    }

    public function leaseUntil(): DateTimeImmutable
    {
        return $this->leaseUntil;
    }

    public function secondsLeft(): float
    {
        return (float) $this->leaseUntil->format('U.u') - microtime(true);
    }

    /**
     * @internal Client::extend() moves the end of the lease.
     */
    public function extendedTo(DateTimeImmutable $leaseUntil): void
    {
        $this->leaseUntil = $leaseUntil;
    }
}
