<?php

declare(strict_types=1);

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

/*
 * Only the channels we add or change. Laravel merges this with the framework defaults.
 * LOG_CHANNEL=json writes one JSON object per line to stderr (container friendly).
 * The request_id is attached through Laravel's Context (see AssignRequestId middleware)
 * and appears under "extra.request_id"; it is also propagated into queued jobs.
 */
return [
    'default' => env('LOG_CHANNEL', 'json'),

    'channels' => [
        'json' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => JsonFormatter::class,
            'processors' => [PsrLogMessageProcessor::class],
        ],
    ],
];
