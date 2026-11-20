<?php

declare(strict_types=1);

namespace LessorMQ\Exception;

/**
 * The server could not be reached, after the configured retries.
 */
class TransportException extends \RuntimeException implements LessorMQException
{
}
