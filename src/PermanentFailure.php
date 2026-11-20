<?php

declare(strict_types=1);

namespace LessorMQ;

/**
 * Implement on an exception to report it as permanent: the input itself cannot be
 * processed. A fact for the server; the queue's on_permanent decides what it means.
 */
interface PermanentFailure extends \Throwable
{
}
