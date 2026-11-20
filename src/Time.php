<?php

declare(strict_types=1);

namespace LessorMQ;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * @internal The API speaks RFC 3339 in UTC.
 */
final class Time
{
    private function __construct()
    {
    }

    public static function parse(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339, $value)
            ?: DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339_EXTENDED, $value);

        if ($time === false) {
            throw new \UnexpectedValueException(sprintf('Not an RFC 3339 time: "%s"', $value));
        }

        return $time->setTimezone(new DateTimeZone('UTC'));
    }

    public static function format(DateTimeInterface $time): string
    {
        return DateTimeImmutable::createFromInterface($time)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
