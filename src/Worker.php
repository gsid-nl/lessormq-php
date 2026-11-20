<?php

declare(strict_types=1);

namespace LessorMQ;

use LessorMQ\Exception\LeaseLostException;
use LessorMQ\Exception\LessorMQException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Claims messages of one queue and runs a handler on each:
 *
 *   (new Worker($client, 'mail', function (Message $message, Job $job): void {
 *       send($message->body);
 *   }))->run();
 *
 * The handler returns: the message is acked. It throws: the message is failed with the
 * exception's class and text, as permanent when the exception implements
 * PermanentFailure. What happens next (another attempt, when, or the dead-letter set)
 * is decided by the queue. While the handler runs, the lease is extended on a timer
 * (ext-pcntl); without pcntl a long handler calls $job->extend() itself.
 */
final class Worker
{
    /** @var callable(Message, Job): mixed */
    private $handler;
    private readonly WorkerOptions $options;
    private readonly LoggerInterface $logger;
    private bool $stopping = false;
    private ?Lease $current = null;
    private bool $leaseLost = false;

    /**
     * @param callable(Message, Job): mixed $handler
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $queue,
        callable $handler,
        ?WorkerOptions $options = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->handler = $handler;
        $this->options = $options ?? new WorkerOptions();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Claim and handle messages until stop(), a signal, or a configured limit.
     *
     * @return int messages handled
     */
    public function run(): int
    {
        $this->stopping = false;
        $handled = 0;
        $started = microtime(true);
        $restoreSignals = $this->options->handleSignals ? $this->trapStopSignals() : null;

        try {
            while (!$this->stopping) {
                if ($this->options->maxMessages !== null && $handled >= $this->options->maxMessages) {
                    break;
                }

                if ($this->options->maxSeconds !== null && microtime(true) - $started >= $this->options->maxSeconds) {
                    break;
                }

                if ($this->runOnce()) {
                    $handled++;
                }
            }
        } finally {
            if ($restoreSignals !== null) {
                $restoreSignals();
            }
        }

        return $handled;
    }

    /**
     * One claim, and the handler on what it got.
     *
     * @return bool whether a message was handled
     */
    public function runOnce(): bool
    {
        try {
            $lease = $this->client->claim($this->queue, new ClaimOptions(
                $this->options->consumer ?? (gethostname() ?: null),
                $this->options->route,
                $this->options->waitSeconds,
                $this->options->leaseSeconds,
            ));
        } catch (LessorMQException $e) {
            $this->logger->error('Claim failed', ['queue' => $this->queue, 'error' => $e->getMessage()]);
            usleep($this->options->errorPauseMs * 1000);

            return false;
        }

        if ($lease === null) {
            return false;
        }

        if ($this->stopping) {
            // Asked to stop while the claim waited: give the message back untouched.
            $this->report(fn() => $this->client->release($lease), $lease, 'release');

            return false;
        }

        $this->handle($lease);

        return true;
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    private function handle(Lease $lease): void
    {
        $this->current = $lease;
        $this->leaseLost = false;
        $stopExtending = $this->startExtending($lease);

        try {
            ($this->handler)($lease->message, new Job($this->client, $lease));
            $error = null;
        } catch (\Throwable $e) {
            $error = $e;
        } finally {
            $stopExtending();
            $this->current = null;
        }

        if ($this->leaseLost) {
            $this->logger->warning('Lease lost while the handler ran; not reporting', [
                'message_id' => $lease->message->id,
            ]);

            return;
        }

        if ($error === null) {
            $this->report(fn() => $this->client->ack($lease), $lease, 'ack');

            return;
        }

        $this->report(function () use ($lease, $error): void {
            $result = $this->client->fail($lease, $error);
            $this->logger->info('Message failed', [
                'message_id' => $lease->message->id,
                'error' => $error->getMessage(),
                'outcome' => $result->outcome,
                'attempt' => $result->attempt,
                'max_attempts' => $result->maxAttempts,
            ]);
        }, $lease, 'fail');
    }

    /**
     * Ack, fail or release, keeping the worker alive whatever happens: a lost lease
     * means the message is someone else's now; any other failure means the lease will
     * expire and the server will make the message available again.
     */
    private function report(callable $call, Lease $lease, string $what): void
    {
        try {
            $call();
        } catch (LeaseLostException) {
            $this->logger->warning('Lease lost before ' . $what . '; the message is no longer ours', [
                'message_id' => $lease->message->id,
            ]);
        } catch (LessorMQException $e) {
            $this->logger->error(ucfirst($what) . ' failed; the lease will expire and the message come back', [
                'message_id' => $lease->message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extend the lease on SIGALRM while the handler runs.
     *
     * @return callable(): void stops extending
     */
    private function startExtending(Lease $lease): callable
    {
        if (!$this->options->autoExtend || !function_exists('pcntl_alarm') || !function_exists('pcntl_signal')) {
            return static function (): void {
            };
        }

        $interval = $this->options->extendEvery ?? max(1, (int) floor($lease->secondsLeft() / 3));
        $previousAsync = pcntl_async_signals(true);
        $previousHandler = pcntl_signal_get_handler(SIGALRM);

        pcntl_signal(SIGALRM, function () use ($interval): void {
            if ($this->current === null || $this->leaseLost) {
                return;
            }

            try {
                $this->client->extend($this->current, $this->options->leaseSeconds);
            } catch (LeaseLostException) {
                $this->leaseLost = true;

                return;
            } catch (LessorMQException $e) {
                $this->logger->warning('Extend failed; trying again', ['error' => $e->getMessage()]);
            }

            pcntl_alarm($interval);
        });
        pcntl_alarm($interval);

        return static function () use ($previousAsync, $previousHandler): void {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
            pcntl_async_signals($previousAsync);
        };
    }

    /**
     * @return (callable(): void)|null restores the previous handlers
     */
    private function trapStopSignals(): ?callable
    {
        if (!function_exists('pcntl_signal')) {
            return null;
        }

        $previousAsync = pcntl_async_signals(true);
        $previous = [];

        foreach ([SIGTERM, SIGINT] as $signal) {
            $previous[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, function (): void {
                $this->stopping = true;
            });
        }

        return static function () use ($previous, $previousAsync): void {
            foreach ($previous as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }

            pcntl_async_signals($previousAsync);
        };
    }
}
