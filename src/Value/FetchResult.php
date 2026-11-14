<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Value;

use IndianOpinion\FeedHealth\Exception\InvalidValue;
use IndianOpinion\FeedHealth\Outcome;

/**
 * One fetch attempt as an importer reports it.
 *
 * feedUrl and feedLabel are optional extras (not in the minimal contract):
 * when given, they name the feed record created or updated by the report.
 */
final class FetchResult
{
    public readonly string $adapter;
    public readonly string $feedKey;
    public readonly string $outcome;
    /** The outcome string as reported, before normalisation. */
    public readonly string $reportedOutcome;
    public readonly ?string $errorMessage;
    public readonly ?string $errorCode;

    /**
     * @throws InvalidValue
     */
    public function __construct(
        string $adapter,
        string $feedKey,
        public readonly int $startedAt,
        public readonly int $finishedAt,
        string $outcome,
        public readonly ?int $httpStatus = null,
        public readonly ?string $finalUrl = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        public readonly int $items = 0,
        public readonly ?int $bytes = null,
        public readonly bool $manual = false,
        public readonly ?string $runId = null,
        public readonly ?string $feedUrl = null,
        public readonly ?string $feedLabel = null,
        public readonly ?int $redirectStatus = null
    ) {
        $this->adapter = Input::adapterKey($adapter);
        $this->feedKey = Input::feedKey($feedKey);
        if (trim($outcome) === '') {
            throw new InvalidValue('Outcome is required.');
        }
        $this->reportedOutcome = $outcome;
        $this->outcome = Outcome::normalise($outcome);
        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidValue('HTTP status must be between 100 and 599.');
        }
        if ($items < 0 || ($bytes !== null && $bytes < 0)) {
            throw new InvalidValue('Counts cannot be negative.');
        }
        if ($finishedAt < $startedAt) {
            throw new InvalidValue('A fetch cannot finish before it starts.');
        }
        $code = $errorCode !== null ? mb_substr($errorCode, 0, 100) : null;
        if ($this->outcome === Outcome::FAILURE_OTHER && $code === null && strtolower(trim($outcome)) !== Outcome::FAILURE_OTHER) {
            $code = mb_substr('outcome:' . trim($outcome), 0, 100);
        }
        $this->errorCode = $code;
        $this->errorMessage = $errorMessage !== null ? mb_substr(self::plain($errorMessage), 0, 300) : null;
    }

    /**
     * Build from a plain array (the action payload). Unknown keys are
     * ignored. Missing required keys throw.
     *
     * @param array<mixed> $data
     * @throws InvalidValue
     */
    public static function fromArray(array $data, int $now): self
    {
        foreach (['adapter', 'feed_key', 'outcome'] as $required) {
            if (!isset($data[$required]) || $data[$required] === '') {
                throw new InvalidValue('Missing ' . $required . '.');
            }
        }
        $started = Input::time($data['started_at'] ?? null) ?? $now;
        $finished = Input::time($data['finished_at'] ?? null) ?? max($started, $now);

        return new self(
            Input::string($data['adapter']),
            Input::feedKey($data['feed_key']),
            $started,
            max($started, $finished),
            Input::string($data['outcome']),
            Input::optionalInt($data['http_status'] ?? null),
            Input::optionalString($data['final_url'] ?? null),
            Input::optionalString($data['error_code'] ?? null),
            Input::optionalString($data['error_message'] ?? null),
            Input::optionalInt($data['items'] ?? null) ?? 0,
            Input::optionalInt($data['bytes'] ?? null),
            (bool) ($data['manual'] ?? false),
            Input::optionalString($data['run_id'] ?? null, 64),
            Input::optionalString($data['feed_url'] ?? null),
            Input::optionalString($data['feed_label'] ?? null, 255),
            Input::optionalInt($data['redirect_status'] ?? null)
        );
    }

    /**
     * Strip tags and control characters: remote messages are plain text.
     */
    private static function plain(string $text): string
    {
        $text = strip_tags($text);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
