<?php

declare(strict_types=1);

namespace AniTools\Util;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

final class ServerLog
{
    private static Logger $instance;

    public static function getInstance(): Logger
    {
        if (isset(self::$instance)) {
            return self::$instance;
        }

        $logger = new Logger('API');
        $formatter = new LineFormatter(
            "%datetime% %context.username%%channel%.%level_name%: %message%\n",
            'Y-m-d H:i:s',
            true,
            true
        );
        // Log to STDOUT for CLI
        $handler = new StreamHandler('php://stdout', Level::Debug);
        $handler->setFormatter($formatter);
        $logger->pushHandler($handler);
        // Also log to a file
        $handler = new StreamHandler('./data/logs/server.log', Level::Debug);
        $handler->setFormatter($formatter);
        $logger->pushHandler($handler);

        self::$instance = $logger;

        return $logger;
    }
}
