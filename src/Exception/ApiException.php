<?php

declare(strict_types=1);

namespace LessorMQ\Exception;

/**
 * The server answered with an error (RFC 9457 problem details). `problemCode` is the
 * stable code to branch on; the subclasses cover the ones a client acts on.
 */
class ApiException extends \RuntimeException implements LessorMQException
{
    /**
     * @param list<array{field: string, message: string}> $errors
     */
    public function __construct(
        public readonly int $status,
        public readonly string $problemCode,
        string $detail,
        public readonly array $errors = [],
    ) {
        parent::__construct($detail === '' ? sprintf('LessorMQ error %d (%s)', $status, $problemCode) : $detail);
    }

    /**
     * @param int|null $retryAfter seconds, from the Retry-After header of a 429
     */
    public static function fromResponse(int $status, mixed $body, ?int $retryAfter = null): self
    {
        $problem = is_array($body) ? $body : [];
        $code = is_string($problem['code'] ?? null) ? $problem['code'] : self::codeFor($status);
        $detail = is_string($problem['detail'] ?? null) ? $problem['detail'] : '';
        $errors = is_array($problem['errors'] ?? null) ? $problem['errors'] : [];

        return match (true) {
            $code === 'lease_lost' => new LeaseLostException($status, $code, $detail, $errors),
            $code === 'validation_failed' => new ValidationException($status, $code, $detail, $errors),
            $code === 'unauthorized' => new AuthenticationException($status, $code, $detail, $errors),
            $code === 'forbidden',
            $code === 'tenant_suspended' => new ForbiddenException($status, $code, $detail, $errors),
            $code === 'not_found' => new NotFoundException($status, $code, $detail, $errors),
            $code === 'invalid_state' => new ConflictException($status, $code, $detail, $errors),
            $code === 'payload_too_large' => new PayloadTooLargeException($status, $code, $detail, $errors),
            $code === 'rate_limited' => new RateLimitedException($status, $code, $detail, $errors, $retryAfter),
            $status >= 500 => new ServerException($status, $code, $detail, $errors),
            default => new self($status, $code, $detail, $errors),
        };
    }

    private static function codeFor(int $status): string
    {
        return match (true) {
            $status === 401 => 'unauthorized',
            $status === 403 => 'forbidden',
            $status === 404 => 'not_found',
            $status === 413 => 'payload_too_large',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'internal',
            default => 'validation_failed',
        };
    }
}
