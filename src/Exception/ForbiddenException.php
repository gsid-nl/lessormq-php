<?php

declare(strict_types=1);

namespace LessorMQ\Exception;

/**
 * The key lacks the scope or the queue, or its tenant is suspended (problemCode tenant_suspended).
 */
class ForbiddenException extends ApiException
{
}
