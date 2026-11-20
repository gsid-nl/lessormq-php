<?php

declare(strict_types=1);

/*
 * The conformance runner (conformance/php/run.php) loads this file when
 * LESSORMQ_ADAPTER points at it, and runs every scenario through this library.
 */

require __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/ClientAdapter.php';

return static fn(string $url): LessorMQ\Conformance\Adapter => new LessorMQ\Tests\Conformance\ClientAdapter($url);
