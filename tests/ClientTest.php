<?php

declare(strict_types=1);

namespace LessorMQ\Tests;

use LessorMQ\ClaimOptions;
use LessorMQ\Client;
use LessorMQ\ClientOptions;
use LessorMQ\Exception\ConflictException;
use LessorMQ\Exception\LeaseLostException;
use LessorMQ\Exception\RateLimitedException;
use LessorMQ\Exception\ServerException;
use LessorMQ\Exception\TransportException;
use LessorMQ\Exception\ValidationException;
use LessorMQ\FailResult;
use LessorMQ\Lease;
use LessorMQ\PermanentFailureException;
use LessorMQ\PublishOptions;
use LessorMQ\Tests\Fake\FakeHttp;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    private FakeHttp $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttp();
    }

    public function testPublishSendsTheBodyAndOptionsWithTheKey(): void
    {
        $this->http->reply(201, $this->published(duplicate: false));

        $published = $this->client()->publish('mail', ['to' => 'a@b.c'], new PublishOptions(
            headers: ['type' => 'Welcome'],
            dedupKey: 'user-7',
            delaySeconds: 30,
            maxAttempts: 3,
        ));

        $request = $this->http->sent[0];
        $this->assertSame('POST /v1/queues/mail/messages', $this->http->path(0));
        $this->assertSame('Bearer lmq_test', $request->getHeaderLine('Authorization'));
        $this->assertSame([
            'body' => ['to' => 'a@b.c'],
            'headers' => ['type' => 'Welcome'],
            'dedup_key' => 'user-7',
            'delay_seconds' => 30,
            'max_attempts' => 3,
        ], $this->http->body(0));
        $this->assertSame('m-1', $published->id);
        $this->assertFalse($published->duplicate);
    }

    public function testPublishGetsADedupKeySoARetryCannotDuplicate(): void
    {
        $this->http->reply(503, ['code' => 'unavailable'])->reply(200, $this->published(duplicate: true));

        $published = $this->client()->publish('mail', 'hello');

        $this->assertCount(2, $this->http->sent);
        $key = $this->http->body(0)['dedup_key'];
        $this->assertMatchesRegularExpression('/^lmq-auto-[0-9a-f]{24}$/', $key);
        $this->assertSame($key, $this->http->body(1)['dedup_key'], 'the retry repeats the same key');
        $this->assertTrue($published->duplicate);
    }

    public function testPublishWithoutAKeyIsNotRepeatedAfterAServerError(): void
    {
        $this->http->reply(503, ['code' => 'unavailable']);

        $this->expectException(ServerException::class);

        try {
            $this->client(new ClientOptions(autoDedupKey: false))->publish('mail', 'hello');
        } finally {
            $this->assertCount(1, $this->http->sent);
        }
    }

    public function testClaimReturnsNullOnEmptyAndALeaseOtherwise(): void
    {
        $this->http->reply(204)->reply(200, $this->lease());

        $this->assertNull($this->client()->claim('mail'));

        $lease = $this->client()->claim('mail', new ClaimOptions('host-1', 'vpn-a', 20, 600));

        $this->assertSame(
            ['consumer' => 'host-1', 'route' => 'vpn-a', 'wait_seconds' => 20, 'lease_seconds' => 600],
            $this->http->body(1),
        );
        $this->assertSame('{}', (string) $this->http->sent[0]->getBody(), 'an empty claim is an empty object');
        $this->assertSame('r-1', $lease->receipt);
        $this->assertSame(['type' => 'Welcome'], $lease->message->headers);
        $this->assertSame(2, $lease->message->attempt);
        $this->assertFalse($lease->message->isLastAttempt());
        $this->assertSame('2030-01-01T00:05:00+00:00', $lease->leaseUntil()->format(DATE_ATOM));
    }

    public function testNetworkErrorsAndServerErrorsAreRetriedWithTheSameReceipt(): void
    {
        $this->http->failNetwork()->reply(502, null)->reply(204);

        $this->client()->ack($this->leaseObject());

        $this->assertCount(3, $this->http->sent);
        $this->assertSame(['receipt' => 'r-1'], $this->http->body(2));
        $this->assertSame('POST /v1/messages/m-1/ack', $this->http->path(2));
    }

    public function testGivesUpAfterTheConfiguredRetries(): void
    {
        $this->http->failNetwork()->failNetwork();

        $this->expectException(TransportException::class);

        $this->client(new ClientOptions(httpRetries: 1))->ack($this->leaseObject());
    }

    public function testRateLimitWaitsRetryAfter(): void
    {
        $this->http->reply(429, ['code' => 'rate_limited'], ['Retry-After' => '0'])->reply(204);

        $this->client()->release($this->leaseObject(), 5);

        $this->assertCount(2, $this->http->sent);
        $this->assertSame(['receipt' => 'r-1', 'delay_seconds' => 5], $this->http->body(1));

        $this->http->reply(429, ['code' => 'rate_limited', 'detail' => 'slow down'], ['Retry-After' => '7']);

        try {
            $this->client(new ClientOptions(httpRetries: 0))->release($this->leaseObject());
            $this->fail('Expected a rate limit');
        } catch (RateLimitedException $e) {
            $this->assertSame(7, $e->retryAfter);
        }
    }

    public function testProblemsBecomeTypedExceptions(): void
    {
        $this->http
            ->reply(409, ['code' => 'lease_lost', 'detail' => 'gone'])
            ->reply(409, ['code' => 'invalid_state'])
            ->reply(400, [
                'code' => 'validation_failed',
                'errors' => [['field' => 'body', 'message' => 'is required']],
            ]);

        try {
            $this->client()->ack($this->leaseObject());
            $this->fail('Expected lease_lost');
        } catch (LeaseLostException $e) {
            $this->assertSame('gone', $e->getMessage());
            $this->assertSame(409, $e->status);
        }

        try {
            $this->client()->ack($this->leaseObject());
            $this->fail('Expected invalid_state');
        } catch (ConflictException $e) {
            $this->assertSame('invalid_state', $e->problemCode);
        }

        try {
            $this->client()->publish('mail', null);
            $this->fail('Expected a validation error');
        } catch (ValidationException $e) {
            $this->assertSame('body', $e->errors[0]['field']);
        }

        $this->assertCount(3, $this->http->sent, 'client errors are never retried');
    }

    public function testFailReportsTheExceptionAndLetsTheServerDecide(): void
    {
        $this->http
            ->reply(200, [
                'outcome' => 'retrying',
                'available_at' => '2030-01-01T00:00:10Z',
                'attempt' => 1,
                'max_attempts' => 7,
            ])
            ->reply(200, ['outcome' => 'dead_lettered', 'available_at' => null, 'attempt' => 1, 'max_attempts' => 7]);

        $result = $this->client()->fail($this->leaseObject(), new \RuntimeException('upstream slow'));

        $body = $this->http->body(0);
        $this->assertSame('r-1', $body['receipt']);
        $this->assertSame(['message' => 'upstream slow', 'type' => 'RuntimeException'], array_intersect_key(
            $body['error'],
            ['type' => 1, 'message' => 1],
        ));
        $this->assertSame(__FILE__, $body['error']['details']['file']);
        $this->assertFalse($body['permanent']);
        $this->assertSame(FailResult::RETRYING, $result->outcome);
        $this->assertNotNull($result->availableAt);

        $result = $this->client()->fail($this->leaseObject(), new PermanentFailureException('cannot decode'));

        $this->assertTrue($this->http->body(1)['permanent']);
        $this->assertSame(FailResult::DEAD_LETTERED, $result->outcome);
        $this->assertNull($result->availableAt);
    }

    public function testExtendMovesTheLease(): void
    {
        $this->http->reply(200, ['lease_until' => '2030-01-01T01:00:00Z']);
        $lease = $this->leaseObject();

        $this->client()->extend($lease, 3600);

        $this->assertSame(['receipt' => 'r-1', 'lease_seconds' => 3600], $this->http->body(0));
        $this->assertSame('2030-01-01T01:00:00+00:00', $lease->leaseUntil()->format(DATE_ATOM));
    }

    public function testStatsReadsTheQueueCounts(): void
    {
        $this->http->reply(200, ['name' => 'mail', 'settings' => [], 'stats' => ['ready' => 3, 'delayed' => 1]]);

        $this->assertSame(['ready' => 3, 'delayed' => 1], $this->client()->stats('mail'));
        $this->assertSame('GET /v1/queues/mail', $this->http->path(0));
        $this->assertSame('', (string) $this->http->sent[0]->getBody());
    }

    private function client(?ClientOptions $options = null): Client
    {
        $factory = new Psr17Factory();

        return new Client(
            'http://lessormq.test/',
            'lmq_test',
            $options ?? new ClientOptions(retryDelayMs: 1),
            $this->http,
            $factory,
            $factory,
        );
    }

    private function leaseObject(): Lease
    {
        return Lease::fromArray($this->lease());
    }

    /**
     * @return array<string, mixed>
     */
    private function lease(): array
    {
        return [
            'receipt' => 'r-1',
            'lease_until' => '2030-01-01T00:05:00Z',
            'message' => [
                'id' => 'm-1',
                'queue' => 'mail',
                'body' => ['to' => 'a@b.c'],
                'headers' => ['type' => 'Welcome'],
                'attempt' => 2,
                'max_attempts' => 7,
                'created_at' => '2030-01-01T00:00:00Z',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function published(bool $duplicate): array
    {
        return [
            'id' => 'm-1',
            'queue' => 'mail',
            'status' => 'pending',
            'created_at' => '2030-01-01T00:00:00Z',
            'available_at' => '2030-01-01T00:00:00Z',
            'duplicate' => $duplicate,
        ];
    }
}
