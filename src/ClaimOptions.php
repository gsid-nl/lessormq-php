<?php

declare(strict_types=1);

namespace LessorMQ;

/**
 * Settings a consumer may pass when it claims; the queue's limits apply.
 */
final class ClaimOptions
{
    /**
     * @param string|null $consumer     a name for this worker, shown to administrators
     * @param string|null $route        the path this worker runs jobs through (VPN, proxy, account)
     * @param int         $waitSeconds  wait up to this long (at most 20) for a message
     * @param int|null    $leaseSeconds lease length, capped by the queue's max_lease_seconds
     */
    public function __construct(
        public readonly ?string $consumer = null,
        public readonly ?string $route = null,
        public readonly int $waitSeconds = 0,
        public readonly ?int $leaseSeconds = null,
    ) {
    }
}
