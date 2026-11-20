<?php

declare(strict_types=1);

namespace LessorMQ\Exception;

/**
 * The receipt is not the current lease of the message: it expired, was released, or
 * the message was claimed again. Stop reporting on it; the server has already made it
 * available again.
 */
class LeaseLostException extends ApiException
{
}
