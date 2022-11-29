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

use Hyperf\Contract\ConfigInterface;
use Hyperf\Utils\ApplicationContext;
use Monolog\DateTimeImmutable;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\Severity;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Throwable;

class SentryHandler extends AbstractProcessingHandler
{
    /**
     * The current application environment (staging|preprod|prod).
     */
    protected string $environment;

    /**
     * Should represent the current version of the calling
     *             software. Can be any string (git commit, version number).
     */
    protected string $release;

    /**
     * The hub object that sends the message to the server.
     */
    protected HubInterface $hub;

    /**
     *  The formatter to use for the logs generated via handleBatch().
     */
    protected ?FormatterInterface $batchFormatter = null;

    /**
     * Indicates if we should report exceptions, if `false` this handler will ignore records with an exception set in the context.
     */
    private bool $reportExceptions;

    /**
     * Indicates if we should use the formatted message instead of just the message.
     */
    private bool $useFormattedMessage;

    /**
     * @param int $level The minimum logging level at which this handler will be triggered
     * @param bool $bubble Whether the messages that are handled can bubble up the stack or not
     */
    public function __construct($level = Logger::DEBUG, bool $bubble = true, bool $reportExceptions = true, bool $useFormattedMessage = false)
    {
        parent::__construct($level, $bubble);

        $container = ApplicationContext::getContainer();
        $config = $container->get(ConfigInterface::class);
        if ($environment = $config->get('sentry.environment')) {
            $this->environment = $environment;
        }
        if ($release = $config->get('sentry.release')) {
            $this->release = $release;
        }
        $this->hub = $container->get(HubInterface::class);
        $this->reportExceptions = $reportExceptions;
        $this->useFormattedMessage = $useFormattedMessage;
    }

    /**
     * {@inheritdoc}
     */
    public function handleBatch(array $records): void
    {
        $level = $this->level;

        // filter records based on their level
        $records = array_filter(
            $records,
            function ($record) use ($level) {
                return $record['level'] >= $level;
            }
        );

        if (! $records) {
            return;
        }

        // the record with the highest severity is the "main" one
        $record = array_reduce(
            $records,
            function ($highest, $record) {
                if ($highest === null || $record['level'] > $highest['level']) {
                    return $record;
                }

                return $highest;
            }
        );

        // the other ones are added as a context item
        $logs = [];
        foreach ($records as $r) {
            $logs[] = $this->processRecord($r);
        }

        if ($logs) {
            $record['context']['logs'] = (string) $this->getBatchFormatter()->formatBatch($logs);
        }

        $this->handle($record);
    }

    /**
     * Sets the formatter for the logs generated by handleBatch().
     */
    public function setBatchFormatter(FormatterInterface $formatter): self
    {
        $this->batchFormatter = $formatter;

        return $this;
    }

    /**
     * Gets the formatter for the logs generated by handleBatch().
     */
    public function getBatchFormatter(): FormatterInterface
    {
        if (! $this->batchFormatter) {
            $this->batchFormatter = $this->getDefaultBatchFormatter();
        }

        return $this->batchFormatter;
    }

    /**
     * Set the release.
     *
     * @param string $value
     */
    public function setRelease($value): self
    {
        $this->release = $value;

        return $this;
    }

    /**
     * Set the current application environment.
     *
     * @param string $value
     */
    public function setEnvironment($value): self
    {
        $this->environment = $value;

        return $this;
    }

    /**
     * Add a breadcrumb.
     *
     * @see https://docs.sentry.io/learn/breadcrumbs/
     */
    public function addBreadcrumb(Breadcrumb $crumb): self
    {
        $this->hub->addBreadcrumb($crumb);

        return $this;
    }

    /**
     * Translates Monolog log levels to Sentry Severity.
     */
    protected function getLogLevel(int $logLevel): Severity
    {
        switch ($logLevel) {
            case Logger::DEBUG:
                return Severity::debug();
            case Logger::NOTICE:
            case Logger::INFO:
                return Severity::info();
            case Logger::WARNING:
                return Severity::warning();
            case Logger::ALERT:
            case Logger::EMERGENCY:
            case Logger::CRITICAL:
                return Severity::fatal();
            case Logger::ERROR:
            default:
                return Severity::error();
        }
    }

    /**
     * {@inheritdoc}
     * @suppress PhanTypeMismatchArgument
     */
    protected function write(array|LogRecord $record): void
    {
        $exception = $record['context']['exception'] ?? null;
        $isException = $exception instanceof Throwable;
        unset($record['context']['exception']);

        if (! $this->reportExceptions && $isException) {
            return;
        }

        $this->hub->withScope(
            function (Scope $scope) use ($record, $isException, $exception) {
                if (! empty($record['context']['extra'])) {
                    foreach ($record['context']['extra'] as $key => $tag) {
                        $scope->setExtra($key, $tag);
                    }
                    unset($record['context']['extra']);
                }

                if (! empty($record['context']['tags'])) {
                    foreach ($record['context']['tags'] as $key => $tag) {
                        $scope->setTag($key, (string) $tag);
                    }
                    unset($record['context']['tags']);
                }

                if (! empty($record['extra'])) {
                    foreach ($record['extra'] as $key => $extra) {
                        $scope->setExtra($key, $extra);
                    }
                }

                if (! empty($record['context']['fingerprint'])) {
                    $scope->setFingerprint($record['context']['fingerprint']);
                    unset($record['context']['fingerprint']);
                }

                if (! empty($record['context']['user'])) {
                    $scope->setUser((array) $record['context']['user']);
                    unset($record['context']['user']);
                }

                $logger = ! empty($record['context']['logger']) ? $record['context']['logger'] : $record['channel'];
                unset($record['context']['logger']);

                if (! empty($record['context'])) {
                    $scope->setExtra('log_context', $record['context']);
                }

                $scope->addEventProcessor(
                    function (Event $event) use ($record, $logger) {
                        $event->setLevel($this->getLogLevel($record['level']));
                        $event->setLogger($logger);

                        if (! empty($this->environment) && ! $event->getEnvironment()) {
                            $event->setEnvironment($this->environment);
                        }

                        if (! empty($this->release) && ! $event->getRelease()) {
                            $event->setRelease($this->release);
                        }

                        if (isset($record['datetime']) && $record['datetime'] instanceof DateTimeImmutable) {
                            $event->setTimestamp($record['datetime']->getTimestamp());
                        }

                        return $event;
                    }
                );

                if ($isException) {
                    $this->hub->captureException($exception);
                } else {
                    $this->hub->captureMessage(
                        $this->useFormattedMessage || empty($record['message'])
                            ? $record['formatted']
                            : $record['message']
                    );
                }
            }
        );
    }

    /**
     * {@inheritDoc}
     */
    protected function getDefaultFormatter(): FormatterInterface
    {
        return new LineFormatter('[%channel%] %message%');
    }

    /**
     * Gets the default formatter for the logs generated by handleBatch().
     */
    protected function getDefaultBatchFormatter(): FormatterInterface
    {
        return new LineFormatter();
    }
}