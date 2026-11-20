# LessorMQ for PHP

Client and worker for [LessorMQ](../../docs/protocol.md), the message queue over HTTP
where the server decides and clients only claim, run, ack or fail.

```sh
composer require lessormq/client
```

Bring any PSR-18 HTTP client (Symfony HttpClient, Guzzle, ...); it is found
automatically, or pass your own. Give it a timeout above 20 seconds, the longest a
claim waits.

## Publish

```php
use LessorMQ\Client;
use LessorMQ\PublishOptions;

$client = new Client('https://mq.example.com', getenv('LESSORMQ_KEY'));

$client->publish('mail', ['to' => 'ada@example.com', 'template' => 'welcome']);

$client->publish('invoices', ['invoice' => 1042], new PublishOptions(
    dedupKey: 'invoice-1042',       // one open message per key
    delaySeconds: 600,
    headers: ['type' => 'InvoiceDue'],
));
```

Every publish carries a dedup key (your own, or one the client makes up), so the
client can safely retry it after a network error without creating a second message.
Pass an object (`(object) []`, `stdClass`) where you mean a JSON object: an empty PHP
array is sent as `[]`.

## Work

```php
use LessorMQ\Job;
use LessorMQ\Message;
use LessorMQ\PermanentFailureException;
use LessorMQ\Worker;
use LessorMQ\WorkerOptions;

$worker = new Worker($client, 'mail', function (Message $message, Job $job): void {
    if (!isset($message->body['to'])) {
        throw new PermanentFailureException('no recipient');
    }

    send_mail($message->body);
}, new WorkerOptions(route: getenv('VPN_ID') ?: null));

$worker->run();
```

- The handler returns: the message is acked.
- It throws: the message is failed with the exception's class and text. Implement
  `LessorMQ\PermanentFailure` (or throw `PermanentFailureException`) when the input
  itself is unusable.
- What happens after a failure is not up to the worker. The queue's settings decide
  whether and when the message comes back, and when it goes to the dead-letter set.
- With ext-pcntl, the lease is extended on a timer while the handler runs, and SIGTERM
  or SIGINT stops the worker after the current message. Without pcntl, a long handler
  calls `$job->extend()` itself. The timer interrupts `sleep()` and `usleep()`, so a
  handler that sleeps may wake early.
- A lost lease, or a server that cannot be reached, never stops the worker: the message
  comes back to someone once its lease ends.

`WorkerOptions` sets the consumer name, route, wait, lease length, extend interval, and
when to stop (`maxMessages`, `maxSeconds`). There is no setting for retries; that is
the queue's job.

## Lower level

`Client` has the six protocol calls: `publish`, `claim`, `extend`, `ack`, `fail` and
`release`. Errors are typed: `LeaseLostException` (the lease ended; stop reporting),
`ValidationException`, `NotFoundException`, `ForbiddenException`,
`RateLimitedException`, `ServerException`, `TransportException`. Network errors, 5xx
and 429 are retried (`ClientOptions::$httpRetries`) wherever repeating is safe.

## Tests

```sh
vendor/bin/phpunit
```

The library also passes the conformance suite of the server repository:

```sh
LESSORMQ_ADAPTER=clients/php/tests/Conformance/adapter.php conformance/run.sh
```
