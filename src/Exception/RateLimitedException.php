<?php

declare(strict_types=1);

namespace LessorMQ\Exception;

/**
 * The tenant exceeded a quota or rate limit, after the configured retries.
 */
class RateLimitedException extends ApiException
{
    /**
     * @param list<array{field: string, message: string}> $errors
     */
    public function __construct(
        int $status,
        string $problemCode,
        string $detail,
        array $errors = [],
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($status, $problemCode, $detail, $errors);
    }
}
