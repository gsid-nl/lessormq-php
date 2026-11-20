<?php

declare(strict_types=1);

namespace LessorMQ;

/**
 * How the client talks HTTP. Nothing here decides what happens to a message.
 *
 * Request timeouts belong to the PSR-18 client you pass in; give it more than 20 seconds
 * when workers claim with a wait.
 */
final class ClientOptions
{
    /**
     * @param int  $httpRetries  retries after a network error, a 5xx or a 429, for calls that
     *                           are safe to repeat (all but a publish without a dedup_key)
     * @param int  $retryDelayMs base delay between retries, times the attempt number
     * @param bool $autoDedupKey give every publish without a dedup_key one of its own, so a
     *                           retried publish can never create a second message
     */
    public function __construct(
        public readonly int $httpRetries = 3,
        public readonly int $retryDelayMs = 100,
        public readonly bool $autoDedupKey = true,
        public readonly string $userAgent = 'lessormq-php/1',
    ) {
    }
}
