<?php

declare(strict_types=1);

namespace LessorMQ\Exception;

/**
 * The server failed (5xx), after the configured retries.
 */
class ServerException extends ApiException
{
}
