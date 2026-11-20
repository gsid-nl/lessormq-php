<?php

declare(strict_types=1);

namespace LessorMQ;

/**
 * How a worker claims and keeps its leases. What happens to a failed message is the
 * queue's business, so there is nothing about retries here.
 */
final class WorkerOptions
{
    /**
     * @param string|null $consumer       shown to administrators; the host name by default
     * @param string|null $route          the path this worker runs jobs through
     * @param int         $waitSeconds    long-poll wait per claim (at most 20)
     * @param int|null    $leaseSeconds   lease per claim; the queue's when null
     * @param int|null    $extendEvery    seconds between automatic extends; a third of the
     *                                    lease when null. Needs ext-pcntl
     * @param bool        $autoExtend     extend the lease while a handler runs (ext-pcntl)
     * @param bool        $handleSignals  stop after the current message on SIGTERM or SIGINT
     * @param int|null    $maxMessages    stop after this many messages
     * @param int|null    $maxSeconds     stop after running this long
     * @param int         $errorPauseMs   pause after the server could not be reached
     */
    public function __construct(
        public readonly ?string $consumer = null,
        public readonly ?string $route = null,
        public readonly int $waitSeconds = 20,
        public readonly ?int $leaseSeconds = null,
        public readonly ?int $extendEvery = null,
        public readonly bool $autoExtend = true,
        public readonly bool $handleSignals = true,
        public readonly ?int $maxMessages = null,
        public readonly ?int $maxSeconds = null,
        public readonly int $errorPauseMs = 1000,
    ) {
    }
}
