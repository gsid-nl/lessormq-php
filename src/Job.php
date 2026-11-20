<?php

declare(strict_types=1);

namespace LessorMQ;

/**
 * What a handler gets besides the message: a way to keep the lease while it works,
 * for when the worker cannot extend it on its own (no pcntl).
 */
final class Job
{
    public function __construct(
        private readonly Client $client,
        public readonly Lease $lease,
    ) {
    }

    /**
     * @throws Exception\LeaseLostException when the lease already ended
     */
    public function extend(?int $leaseSeconds = null): void
    {
        $this->client->extend($this->lease, $leaseSeconds);
    }
}
