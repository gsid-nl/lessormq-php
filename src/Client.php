<?php

declare(strict_types=1);

namespace LessorMQ;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use LessorMQ\Exception\ApiException;
use LessorMQ\Exception\LeaseLostException;
use LessorMQ\Exception\RateLimitedException;
use LessorMQ\Exception\ServerException;
use LessorMQ\Exception\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The six protocol calls of LessorMQ (docs/protocol.md), nothing more.
 *
 * The client never decides what happens to a message: no retry of a failed job, no
 * delay, no dead-lettering. It reports, and the queue decides. It does retry its own
 * HTTP calls when that is safe: network errors, 5xx and 429, for every call that can be
 * repeated (all of them, given the automatic dedup_key on publish).
 */
final class Client
{
    private readonly ClientInterface $http;
    private readonly RequestFactoryInterface $requests;
    private readonly StreamFactoryInterface $streams;
    private readonly ClientOptions $options;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly string $baseUrl,
        #[\SensitiveParameter]
        private readonly string $apiKey,
        ?ClientOptions $options = null,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requests = null,
        ?StreamFactoryInterface $streams = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->options = $options ?? new ClientOptions();
        $this->http = $http ?? Psr18ClientDiscovery::find();
        $this->requests = $requests ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streams = $streams ?? Psr17FactoryDiscovery::findStreamFactory();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Put a message on a queue. With a dedup_key (yours, or the automatic one), an open
     * message with that key is returned instead of a new one: `$published->duplicate`.
     *
     * @param mixed $body any JSON value; pass an object (or stdClass) for a JSON object
     */
    public function publish(string $queue, mixed $body, ?PublishOptions $options = null): Published
    {
        $options ??= new PublishOptions();
        $dedupKey = $options->dedupKey
            ?? ($this->options->autoDedupKey ? 'lmq-auto-' . bin2hex(random_bytes(12)) : null);

        $payload = array_filter([
            'body' => $body,
            'headers' => $options->headers === [] ? null : $options->headers,
            'dedup_key' => $dedupKey,
            'delay_seconds' => $options->delaySeconds,
            'available_at' => $options->availableAt === null ? null : Time::format($options->availableAt),
            'max_attempts' => $options->maxAttempts,
        ], static fn(mixed $value) => $value !== null);
        $payload['body'] = $body;

        $path = '/v1/queues/' . rawurlencode($queue) . '/messages';
        $response = $this->send('POST', $path, $payload, $dedupKey !== null);

        return Published::fromArray($response['body']);
    }

    /**
     * Lease the next message of a queue, waiting up to waitSeconds for one. Null when
     * nothing became available (or the queue is paused).
     */
    public function claim(string $queue, ?ClaimOptions $options = null): ?Lease
    {
        // A claim whose answer got lost leaves a lease that simply expires and counts as
        // an attempt, so claims are retried like the rest.
        $response = $this->send(
            'POST',
            '/v1/queues/' . rawurlencode($queue) . '/claims',
            (object) $this->claimPayload($options ?? new ClaimOptions()),
            true,
        );

        return $response['status'] === 204 ? null : Lease::fromArray($response['body']);
    }

    /**
     * Lease up to maxMessages (1 to 100) ready messages of a queue at once, oldest first,
     * each with a receipt of its own. Waits up to waitSeconds only while there is none; an
     * empty list when nothing became available. Report each lease as usual, or ack them
     * together with ackBatch().
     *
     * @return list<Lease>
     */
    public function claimBatch(string $queue, int $maxMessages, ?ClaimOptions $options = null): array
    {
        $payload = $this->claimPayload($options ?? new ClaimOptions());
        $payload['max_messages'] = $maxMessages;
        $response = $this->send('POST', '/v1/queues/' . rawurlencode($queue) . '/claims', $payload, true);

        if ($response['status'] === 204) {
            return [];
        }

        return array_map(
            static fn(array $lease) => Lease::fromArray($lease),
            array_values($response['body']['items'] ?? []),
        );
    }

    /**
     * Report success for up to 100 leases in one request. The outcomes are in the order
     * of the leases; one that cannot be acked does not hold up the others.
     *
     * @param list<Lease> $leases
     * @return list<AckOutcome>
     */
    public function ackBatch(array $leases): array
    {
        $payload = ['leases' => array_map(
            static fn(Lease $lease) => ['message' => $lease->message->id, 'receipt' => $lease->receipt],
            array_values($leases),
        )];
        $items = $this->send('POST', '/v1/acks', $payload, true)['body']['items'] ?? [];
        $outcomes = [];

        foreach (array_keys(array_values($leases)) as $i) {
            $outcomes[] = AckOutcome::tryFrom((string) ($items[$i]['outcome'] ?? '')) ?? AckOutcome::NotFound;
        }

        return $outcomes;
    }

    /**
     * @return array<string, mixed>
     */
    private function claimPayload(ClaimOptions $options): array
    {
        return array_filter([
            'consumer' => $options->consumer,
            'route' => $options->route,
            'wait_seconds' => $options->waitSeconds > 0 ? $options->waitSeconds : null,
            'lease_seconds' => $options->leaseSeconds,
        ], static fn(mixed $value) => $value !== null);
    }

    /**
     * Move the end of the lease to now plus leaseSeconds (or the queue's lease).
     *
     * @throws LeaseLostException when the lease already ended
     */
    public function extend(Lease $lease, ?int $leaseSeconds = null): Lease
    {
        $payload = ['receipt' => $lease->receipt];

        if ($leaseSeconds !== null) {
            $payload['lease_seconds'] = $leaseSeconds;
        }

        $response = $this->send('POST', $this->messagePath($lease, 'extend'), $payload, true);
        $lease->extendedTo(Time::parse((string) $response['body']['lease_until']));

        return $lease;
    }

    /**
     * Report success.
     *
     * @throws LeaseLostException when the lease already ended
     */
    public function ack(Lease $lease): void
    {
        $this->send('POST', $this->messagePath($lease, 'ack'), ['receipt' => $lease->receipt], true);
    }

    /**
     * Report failure. The server decides what follows and says so in the result.
     *
     * @param \Throwable|string $error     the exception, or a message
     * @param bool|null         $permanent the input itself cannot be processed; by default,
     *                                     whether $error implements PermanentFailure
     * @param mixed             $details   any JSON value with more context
     * @param string|null       $type      the error type; by default the exception class
     *
     * @throws LeaseLostException when the lease already ended
     */
    public function fail(
        Lease $lease,
        \Throwable|string $error,
        ?bool $permanent = null,
        mixed $details = null,
        ?string $type = null,
    ): FailResult {
        $text = $error instanceof \Throwable ? $error->getMessage() : $error;
        $report = ['message' => mb_substr($text, 0, 65535) ?: '(no message)'];
        $type ??= $error instanceof \Throwable ? $error::class : null;

        if ($type !== null) {
            $report['type'] = mb_substr($type, 0, 255);
        }

        if ($details === null && $error instanceof \Throwable) {
            $details = ['file' => $error->getFile(), 'line' => $error->getLine()];
        }

        if ($details !== null) {
            $report['details'] = $details;
        }

        $response = $this->send('POST', $this->messagePath($lease, 'fail'), [
            'receipt' => $lease->receipt,
            'error' => $report,
            'permanent' => $permanent ?? $error instanceof PermanentFailure,
        ], true);

        return FailResult::fromArray($response['body']);
    }

    /**
     * Give the message back without an outcome; the attempt does not count.
     *
     * @throws LeaseLostException when the lease already ended
     */
    public function release(Lease $lease, int $delaySeconds = 0): void
    {
        $payload = ['receipt' => $lease->receipt];

        if ($delaySeconds > 0) {
            $payload['delay_seconds'] = $delaySeconds;
        }

        $this->send('POST', $this->messagePath($lease, 'release'), $payload, true);
    }

    /**
     * A queue's counts: ready, delayed, processing, completed, failed, and the age in
     * seconds of its oldest ready and processing message. Needs the consume or admin
     * scope. Not part of the protocol; for monitoring.
     *
     * @return array<string, int>
     */
    public function stats(string $queue): array
    {
        $response = $this->send('GET', '/v1/queues/' . rawurlencode($queue), null, true);

        return array_map('intval', $response['body']['stats'] ?? []);
    }

    private function messagePath(Lease $lease, string $action): string
    {
        return '/v1/messages/' . rawurlencode($lease->message->id) . '/' . $action;
    }

    /**
     * @param bool $repeatable whether the call may be sent again after a failure that
     *                         leaves its effect unknown (network error, 5xx)
     * @return array{status: int, body: mixed}
     */
    private function send(string $method, string $path, array|object|null $payload, bool $repeatable): array
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->http->sendRequest($this->request($method, $path, $payload));
            } catch (ClientExceptionInterface $e) {
                if ($repeatable && $attempt <= $this->options->httpRetries) {
                    $this->pause($attempt, null, $path, $e->getMessage());
                    continue;
                }

                throw new TransportException(sprintf('%s %s failed: %s', $method, $path, $e->getMessage()), 0, $e);
            }

            $status = $response->getStatusCode();
            $raw = (string) $response->getBody();
            $body = $raw === '' ? null : json_decode($raw, true);

            if ($status < 400) {
                return ['status' => $status, 'body' => $body];
            }

            $retryAfter = $response->hasHeader('Retry-After') ? (int) $response->getHeaderLine('Retry-After') : null;
            $error = ApiException::fromResponse($status, $body, $retryAfter);

            // A 429 was not processed at all, so it is always safe to send again.
            $retry = $error instanceof RateLimitedException || ($repeatable && $error instanceof ServerException);

            if ($retry && $attempt <= $this->options->httpRetries) {
                $this->pause($attempt, $retryAfter, $path, $error->getMessage());
                continue;
            }

            throw $error;
        }
    }

    private function request(string $method, string $path, array|object|null $payload): RequestInterface
    {
        $request = $this->requests->createRequest($method, rtrim($this->baseUrl, '/') . $path)
            ->withHeader('Authorization', 'Bearer ' . $this->apiKey)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', $this->options->userAgent);

        if ($payload === null) {
            return $request;
        }

        return $request
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream((string) json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            )));
    }

    private function pause(int $attempt, ?int $retryAfter, string $path, string $reason): void
    {
        $delayMs = $retryAfter !== null ? $retryAfter * 1000 : $this->options->retryDelayMs * $attempt;
        $this->logger->warning('LessorMQ request failed, retrying', [
            'path' => $path,
            'attempt' => $attempt,
            'delay_ms' => $delayMs,
            'reason' => $reason,
        ]);
        usleep($delayMs * 1000);
    }
}
