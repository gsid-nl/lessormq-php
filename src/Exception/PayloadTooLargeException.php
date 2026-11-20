<?php

declare(strict_types=1);

namespace LessorMQ\Exception;

/**
 * Body and headers exceed the queue's max_message_bytes.
 */
class PayloadTooLargeException extends ApiException
{
}
