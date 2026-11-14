<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth;

/**
 * Writes to the plugin's event log and, when asked, to the PHP error log
 * with one fixed prefix. Never throws.
 */
final class Logger
{
    public const PREFIX = '[feed-health] ';

    /** @var array<string, true> */
    private array $once = [];

    public function __construct(private ?Repository $repository = null, private ?Clock $clock = null)
    {
    }

    /**
     * Write one line to the PHP error log with the fixed prefix.
     */
    public function php(string $message): void
    {
        error_log(self::PREFIX . str_replace(["\r", "\n"], ' ', $message));
    }

    /**
     * Record an event row. Falls back to the PHP error log when the
     * database refuses.
     *
     * @param array<string, mixed>|null $data
     */
    public function event(string $kind, string $message, ?int $feedId = null, ?int $userId = null, ?array $data = null): void
    {
        try {
            if ($this->repository === null || $this->clock === null) {
                throw new \RuntimeException('No repository.');
            }
            $this->repository->addEvent($this->clock->now(), $kind, $message, $feedId, $userId, null, 0, $data);
        } catch (\Throwable $e) {
            $this->php($kind . ': ' . $message);
        }
    }

    /**
     * Log a problem at most once per request per topic, to the PHP error
     * log and, when the database works, to the event log.
     */
    public function once(string $topic, string $message): void
    {
        if (isset($this->once[$topic])) {
            return;
        }
        $this->once[$topic] = true;
        $this->php($message);
        try {
            if ($this->repository !== null && $this->clock !== null) {
                $this->repository->addEvent($this->clock->now(), 'error', $message, null, null, null, 0, null);
            }
        } catch (\Throwable $e) {
            // The PHP error log line above is the record.
            unset($e);
        }
    }
}
