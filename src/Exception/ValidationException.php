<?php

declare(strict_types=1);

namespace LessorMQ\Exception;

/**
 * The request was rejected as invalid; `errors` names each field.
 */
class ValidationException extends ApiException
{
}
