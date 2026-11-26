# LessorMQ for PHP

Client and worker for [LessorMQ](https://lessormq.dev), the message queue over HTTP
where the server decides and clients only claim, run, ack or fail. PHP 8.2+. Package
`lessormq/client`.

## Install

```sh
composer require lessormq/client
```

Bring any PSR-18 HTTP client (Symfony HttpClient, Guzzle, ...); it is found
automatically, or pass your own. The PSR-7 messages come with the client (nyholm/psr7),
so `symfony/http-client` alone is enough.

## Connect

```php
use LessorMQ\Client;

$client = new Client('https://mq.example.com', getenv('LESSORMQ_KEY'));
```

The key is a tenant's API key with the scopes the program needs (`publish`, `consume`).
After the key, `Client` takes a `ClientOptions`, your own PSR-18 client, PSR-17
factories and a PSR-3 logger, all optional. Give your HTTP client a timeout above 20
seconds, the longest a claim waits.

| `ClientOptions` | Default | |
|---|---|---|
| `httpRetries` | 3 | retries after a network error, a 5xx or a 429 |
| `retryDelayMs` | 100 | times the attempt number |
| `autoDedupKey` | true | a publish without a dedup key gets one, so a retry cannot create a second message |
| `userAgent` | `lessormq-php/<version>` | the version Composer installed |

## Publish

```php
use LessorMQ\PublishOptions;

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

## Without the worker

`Client` has the six protocol calls: `publish`, `claim`, `extend`, `ack`, `fail` and
`release`.

```php
use LessorMQ\ClaimOptions;

$lease = $client->claim('mail', new ClaimOptions(waitSeconds: 20));
if ($lease !== null) {
    try {
        send_mail($lease->message->body);
        $client->ack($lease);
    } catch (\Throwable $e) {
        $result = $client->fail($lease, $e); // $result->outcome: retrying, dead_lettered or discarded
    }
}
```

`extend()` keeps a lease while a long job runs, and `release()` hands a message back
without counting the attempt. Once the lease has ended they throw
`LeaseLostException`: drop the message then, it is someone else's now.

Many short jobs go faster in batches: `$client->claimBatch('mail', 50)` leases up to 50
ready messages at once (each with its own lease), and `$client->ackBatch($leases)` acks
them in one request, with an `AckOutcome` per lease. A message that fails is reported on
its own with `fail()`.

## Errors

Errors are typed: `LeaseLostException` (the lease ended; stop reporting),
`ValidationException`, `NotFoundException`, `ForbiddenException`,
`RateLimitedException`, `ServerException`, `TransportException`. Network errors, 5xx
and 429 are retried (see [Connect](#connect)) wherever repeating is safe. That is the
only retrying the library does.

## Development

```sh
cd clients/php
composer install
vendor/bin/phpunit
```

The library passes the LessorMQ [conformance suite](https://github.com/gsid-nl/lessormq/tree/main/conformance),
from the repository root:

```sh
LESSORMQ_ADAPTER=clients/php/tests/Conformance/adapter.php conformance/run.sh
```
