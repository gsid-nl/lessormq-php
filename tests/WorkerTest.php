<?php

declare(strict_types=1);

namespace LessorMQ\Tests;

use LessorMQ\Client;
use LessorMQ\ClientOptions;
use LessorMQ\Job;
use LessorMQ\Message;
use LessorMQ\PermanentFailureException;
use LessorMQ\Tests\Fake\FakeHttp;
use LessorMQ\Worker;
use LessorMQ\WorkerOptions;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class WorkerTest extends TestCase
{
    private FakeHttp $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttp();
    }

    public function testAcksWhenTheHandlerReturns(): void
    {
        $this->http->reply(200, $this->lease())->reply(204);
        $seen = null;

        $handled = $this->worker(function (Message $message) use (&$seen): void {
            $seen = $message->body;
        }, new WorkerOptions(consumer: 'w1', route: 'vpn-a', maxMessages: 1))->run();

        $this->assertSame(1, $handled);
        $this->assertSame(['n' => 1], $seen);
        $this->assertSame(['consumer' => 'w1', 'route' => 'vpn-a', 'wait_seconds' => 20], $this->http->body(0));
        $this->assertSame('POST /v1/messages/m-1/ack', $this->http->path(1));
    }

    public function testFailsWithTheExceptionWhenTheHandlerThrows(): void
    {
        $this->http->reply(200, $this->lease())->reply(200, $this->failed())
            ->reply(200, $this->lease())->reply(200, $this->failed());

        $worker = $this->worker(function (): void {
            static $calls = 0;

            throw ++$calls === 1 ? new \DomainException('try later') : new PermanentFailureException('bad input');
        }, new WorkerOptions(maxMessages: 2));
        $worker->run();

        $this->assertSame('POST /v1/messages/m-1/fail', $this->http->path(1));
        $this->assertSame('DomainException', $this->http->body(1)['error']['type']);
        $this->assertFalse($this->http->body(1)['permanent']);
        $this->assertTrue($this->http->body(3)['permanent']);
    }

    public function testALostLeaseOrAnUnreachableServerNeverStopsTheWorker(): void
    {
        $worker = null;
        $this->http->reply(200, $this->lease())->reply(409, ['code' => 'lease_lost'])
            ->reply(500, null)->reply(500, null)->reply(500, null)->reply(500, null)
            ->answer(function () use (&$worker): Response {
                $worker->stop();

                return new Response(204);
            });

        $worker = $this->worker(static function (): void {
        }, new WorkerOptions(handleSignals: false, errorPauseMs: 0));

        // The lost ack is dropped, the failing claim survived, nothing is thrown
        $this->assertSame(1, $worker->run());
        $this->assertCount(7, $this->http->sent);
    }

    public function testStoppingWhileAClaimWaitsReleasesTheMessage(): void
    {
        $worker = null;
        $this->http->answer(function (RequestInterface $request) use (&$worker): Response {
            $worker->stop();

            return new Response(200, [], (string) json_encode($this->lease()));
        })->reply(204);

        $worker = $this->worker(function (): void {
            $this->fail('the handler must not run');
        });
        $worker->run();

        $this->assertSame('POST /v1/messages/m-1/release', $this->http->path(1));
    }

    #[RequiresFunction('pcntl_alarm')]
    public function testExtendsTheLeaseWhileALongHandlerRuns(): void
    {
        $this->http->reply(200, $this->lease(leaseSeconds: 3));

        for ($i = 0; $i < 5; $i++) {
            $this->http->reply(200, ['lease_until' => gmdate('Y-m-d\TH:i:s\Z', time() + 3)]);
        }

        $this->http->reply(204);

        $this->worker(static function (Message $message, Job $job): void {
            // Busy for 2.5 s: sleep() would be cut short by the alarm that extends
            $until = microtime(true) + 2.5;

            while (microtime(true) < $until) {
                usleep(10_000);
            }
        }, new WorkerOptions(maxMessages: 1))->run();

        $paths = array_map(fn(int $i) => $this->http->path($i), array_keys($this->http->sent));
        $extends = count(array_filter($paths, static fn(string $path) => str_ends_with($path, '/extend')));

        $this->assertGreaterThanOrEqual(2, $extends, implode(', ', $paths));
        $this->assertSame('POST /v1/messages/m-1/ack', end($paths));
    }

    private function worker(callable $handler, ?WorkerOptions $options = null): Worker
    {
        $factory = new Psr17Factory();
        $client = new Client(
            'http://lessormq.test',
            'lmq_test',
            new ClientOptions(retryDelayMs: 1),
            $this->http,
            $factory,
            $factory,
        );

        return new Worker($client, 'mail', $handler, $options ?? new WorkerOptions(handleSignals: false));
    }

    /**
     * @return array<string, mixed>
     */
    private function lease(int $leaseSeconds = 300): array
    {
        return [
            'receipt' => 'r-1',
            'lease_until' => gmdate('Y-m-d\TH:i:s\Z', time() + $leaseSeconds),
            'message' => [
                'id' => 'm-1',
                'queue' => 'mail',
                'body' => ['n' => 1],
                'headers' => [],
                'attempt' => 1,
                'max_attempts' => 7,
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failed(): array
    {
        return [
            'outcome' => 'retrying',
            'available_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'attempt' => 1,
            'max_attempts' => 7,
        ];
    }
}
