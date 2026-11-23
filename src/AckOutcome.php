<?php

declare(strict_types=1);

namespace LessorMQ;

/**
 * What became of one lease of Client::ackBatch().
 */
enum AckOutcome: string
{
    /** The message is completed (also when this lease was acked before). */
    case Acked = 'acked';
    /** The receipt is not the current lease; stop reporting on it. */
    case LeaseLost = 'lease_lost';
    /** No such message. */
    case NotFound = 'not_found';
    /** The key may not use the message's queue. */
    case Forbidden = 'forbidden';
}
