<?php

declare(strict_types=1);

namespace LessorMQ;

/**
 * Throw from a handler when the message itself cannot be processed (it cannot be
 * decoded, it fails validation). Retrying it would only fail again.
 */
class PermanentFailureException extends \RuntimeException implements PermanentFailure
{
}
