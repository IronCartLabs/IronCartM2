<?php

/**
 * IronCart_Scan — async scan run message DTO.
 *
 * Frozen wire shape for the `ironcart.scan.run` topic. Lives in
 * Model\Message because it is a transport concern (consumed by both
 * ScanRunPublisher and ScanRunConsumer); not promoted to Api/ because
 * it is not part of the public web-API surface.
 *
 * The payload is intentionally narrow: only the run id and the
 * triggered_by marker are carried. The consumer rehydrates the row
 * straight from the DB (status=`queued`) and runs the existing
 * CheckRegistry — no scan-time options travel through the queue.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Model\Message;

use InvalidArgumentException;

/**
 * Immutable value object describing a pending scan run.
 */
final class ScanRunMessage
{
    public function __construct(
        private readonly int $scanRunId,
        private readonly string $triggeredBy
    ) {
        if ($scanRunId <= 0) {
            throw new InvalidArgumentException('scanRunId must be a positive integer');
        }
        if ($triggeredBy === '') {
            throw new InvalidArgumentException('triggeredBy must not be empty');
        }
    }

    public function getScanRunId(): int
    {
        return $this->scanRunId;
    }

    public function getTriggeredBy(): string
    {
        return $this->triggeredBy;
    }

    /**
     * Encode to the JSON payload that travels over the queue. Stable
     * shape — clients reading findings out of `summary_json` should
     * not need to inspect this.
     *
     * @return array{scan_run_id:int,triggered_by:string}
     */
    public function toArray(): array
    {
        return [
            'scan_run_id' => $this->scanRunId,
            'triggered_by' => $this->triggeredBy,
        ];
    }

    /**
     * Decode the topic payload back into an instance. Throws on any
     * structural drift so the consumer fails fast rather than silently
     * skipping a run.
     *
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        if (!isset($payload['scan_run_id']) || !is_int($payload['scan_run_id'])) {
            throw new InvalidArgumentException('scan_run_id missing or not an int');
        }
        if (!isset($payload['triggered_by']) || !is_string($payload['triggered_by'])) {
            throw new InvalidArgumentException('triggered_by missing or not a string');
        }

        return new self($payload['scan_run_id'], $payload['triggered_by']);
    }
}
