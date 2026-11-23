<?php

declare(strict_types=1);

namespace LessorMQ\Tests\Fake;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers from a script and records what it was sent.
 */
final class FakeHttp implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $sent = [];

    /** @var list<ResponseInterface|\Throwable|callable(RequestInterface): ResponseInterface> */
    private array $script = [];

    public function reply(int $status, mixed $body = null, array $headers = []): self
    {
        $this->script[] = new Response(
            $status,
            $headers + ($body === null ? [] : ['Content-Type' => 'application/json']),
            $body === null ? '' : json_encode($body),
        );

        return $this;
    }

    public function failNetwork(): self
    {
        $this->script[] = new class ('connection reset') extends \RuntimeException implements
            NetworkExceptionInterface
        {
            public function getRequest(): RequestInterface
            {
                throw new \LogicException('not needed');
            }
        };

        return $this;
    }

    /**
     * @param callable(RequestInterface): ResponseInterface $answer
     */
    public function answer(callable $answer): self
    {
        $this->script[] = $answer;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;
        $next = array_shift($this->script)
            ?? throw new \LogicException('No scripted response for ' . $request->getUri());

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return is_callable($next) ? $next($request) : $next;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(int $index): mixed
    {
        return json_decode((string) $this->sent[$index]->getBody(), true);
    }

    /** The request body as it was sent. */
    public function raw(int $index): string
    {
        return (string) $this->sent[$index]->getBody();
    }

    public function path(int $index): string
    {
        return $this->sent[$index]->getMethod() . ' ' . $this->sent[$index]->getUri()->getPath();
    }
}
