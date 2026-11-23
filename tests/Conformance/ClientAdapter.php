<?php

declare(strict_types=1);

namespace LessorMQ\Tests\Conformance;

use DateTimeImmutable;
use LessorMQ\ClaimOptions;
use LessorMQ\Client;
use LessorMQ\ClientOptions;
use LessorMQ\Conformance\Adapter;
use LessorMQ\Exception\ApiException;
use LessorMQ\Lease;
use LessorMQ\Message;
use LessorMQ\PublishOptions;
use LessorMQ\Time;

/**
 * Runs the conformance scenarios through this library's Client instead of raw HTTP, and
 * turns its results and exceptions back into the {status, body} the scenarios check.
 */
final class ClientAdapter implements Adapter
{
    /** @var array<string, Client> one client per API key */
    private array $clients = [];

    public function __construct(private readonly string $baseUrl)
    {
    }

    public function call(string $call, string $apiKey, array $args): array
    {
        $client = $this->clients[$apiKey] ??= new Client($this->baseUrl, $apiKey, new ClientOptions(retryDelayMs: 50));

        try {
            return match ($call) {
                'publish' => $this->publish($client, $args),
                'claim' => $this->claim($client, $args),
                'extend' => ['status' => 200, 'body' => [
                    'lease_until' => Time::format(
                        $client->extend($this->lease($args), $args['lease_seconds'] ?? null)->leaseUntil(),
                    ),
                ]],
                'ack' => $this->noContent(fn() => $client->ack($this->lease($args))),
                'release' => $this->noContent(
                    fn() => $client->release($this->lease($args), $args['delay_seconds'] ?? 0),
                ),
                'fail' => $this->fail($client, $args),
                'claim_batch' => $this->claimBatch($client, $args),
                'ack_batch' => $this->ackBatch($client, $args),
                default => throw new \InvalidArgumentException('Unknown call ' . $call),
            };
        } catch (ApiException $e) {
            return ['status' => $e->status, 'body' => [
                'code' => $e->problemCode,
                'detail' => $e->getMessage(),
                'errors' => $e->errors,
            ]];
        }
    }

    /**
     * @param array<string, mixed> $args
     * @return array{status: int, body: mixed}
     */
    private function publish(Client $client, array $args): array
    {
        $published = $client->publish($args['queue'], $args['body'] ?? null, new PublishOptions(
            $args['headers'] ?? [],
            $args['dedup_key'] ?? null,
            $args['delay_seconds'] ?? null,
            isset($args['available_at']) ? Time::parse($args['available_at']) : null,
            $args['max_attempts'] ?? null,
        ));

        return ['status' => $published->duplicate ? 200 : 201, 'body' => [
            'id' => $published->id,
            'queue' => $published->queue,
            'status' => $published->status,
            'created_at' => Time::format($published->createdAt),
            'available_at' => Time::format($published->availableAt),
            'duplicate' => $published->duplicate,
        ]];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{status: int, body: mixed}
     */
    private function claim(Client $client, array $args): array
    {
        $lease = $client->claim($args['queue'], $this->claimOptions($args));

        if ($lease === null) {
            return ['status' => 204, 'body' => null];
        }

        return ['status' => 200, 'body' => $this->leaseBody($lease)];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{status: int, body: mixed}
     */
    private function claimBatch(Client $client, array $args): array
    {
        $leases = $client->claimBatch($args['queue'], (int) $args['max_messages'], $this->claimOptions($args));

        if ($leases === []) {
            return ['status' => 204, 'body' => null];
        }

        return ['status' => 200, 'body' => ['items' => array_map($this->leaseBody(...), $leases)]];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{status: int, body: mixed}
     */
    private function ackBatch(Client $client, array $args): array
    {
        $items = array_values($args['leases'] ?? []);
        $outcomes = $client->ackBatch(array_map($this->lease(...), $items));
        $body = [];

        foreach ($items as $i => $item) {
            $body[] = ['message' => $item['message'], 'outcome' => $outcomes[$i]->value];
        }

        return ['status' => 200, 'body' => ['items' => $body]];
    }

    /**
     * @param array<string, mixed> $args
     */
    private function claimOptions(array $args): ClaimOptions
    {
        return new ClaimOptions(
            $args['consumer'] ?? null,
            $args['route'] ?? null,
            $args['wait_seconds'] ?? 0,
            $args['lease_seconds'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function leaseBody(Lease $lease): array
    {
        $message = $lease->message;

        return [
            'receipt' => $lease->receipt,
            'lease_until' => Time::format($lease->leaseUntil()),
            'message' => [
                'id' => $message->id,
                'queue' => $message->queue,
                'body' => $message->body,
                'headers' => $message->headers,
                'attempt' => $message->attempt,
                'max_attempts' => $message->maxAttempts,
                'created_at' => Time::format($message->createdAt),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{status: int, body: mixed}
     */
    private function fail(Client $client, array $args): array
    {
        $error = $args['error'] ?? [];
        $result = $client->fail(
            $this->lease($args),
            (string) ($error['message'] ?? ''),
            $args['permanent'] ?? false,
            $error['details'] ?? null,
            $error['type'] ?? null,
        );

        return ['status' => 200, 'body' => [
            'outcome' => $result->outcome,
            'available_at' => $result->availableAt === null ? null : Time::format($result->availableAt),
            'attempt' => $result->attempt,
            'max_attempts' => $result->maxAttempts,
        ]];
    }

    /**
     * A lease as the scenario names it: the message id and the receipt, which may be stale
     * or someone else's on purpose.
     *
     * @param array<string, mixed> $args
     */
    private function lease(array $args): Lease
    {
        $now = new DateTimeImmutable();

        return new Lease(
            (string) $args['receipt'],
            $now,
            new Message((string) $args['message'], '', null, [], 0, 0, $now),
        );
    }

    /**
     * @return array{status: int, body: null}
     */
    private function noContent(callable $call): array
    {
        $call();

        return ['status' => 204, 'body' => null];
    }
}
