<?php

declare(strict_types=1);
/**
 * This file is part of friendsofhyperf/sentry.
 *
 * @link     https://github.com/friendsofhyperf/sentry
 * @document https://github.com/friendsofhyperf/sentry/blob/main/README.md
 * @contact  huangdijia@gmail.com
 */
namespace FriendsOfHyperf\Sentry;

class ConfigProvider
{
    public function __invoke(): array
    {
        defined('BASE_PATH') or define('BASE_PATH', '');

        return [
            'dependencies' => [
                \Sentry\ClientBuilderInterface::class => Factory\ClientBuilderFactory::class,
                \Sentry\State\HubInterface::class => Factory\HubFactory::class,
            ],
            'commands' => [
                Command\TestCommand::class,
            ],
            'listeners' => [
                Listener\InitHubListener::class,
                Listener\DbQueryListener::class,
            ],
            'scan' => [
                'class_map' => [
                    \Sentry\SentrySdk::class => __DIR__ . '/../class_map/SentrySdk.php',
                ],
            ],
            'aspects' => [
                Aspect\HttpClientAspect::class,
                Aspect\LoggerAspect::class,
                Aspect\RedisAspect::class,
                Aspect\SingletonAspect::class,
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config file for sentry.',
                    'source' => __DIR__ . '/../publish/sentry.php',
                    'destination' => BASE_PATH . '/config/autoload/sentry.php',
                ],
            ],
        ];
    }
}