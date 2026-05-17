<?php

/**
 * IronCart_Scan — in-memory recording logger.
 *
 * Captures every PSR-3 call as a (level, message, context) tuple so
 * tests can assert on the cron's log output without driving a real
 * Monolog handler. Lives in its own file to satisfy the Magento2 /
 * PSR-12 "Each class must be in a file by itself" sniff.
 *
 * Test-only fixture for {@see \IronCart\Scan\Test\Unit\Cron\UploadScanTest}.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Cron;

use Psr\Log\LoggerInterface;

/**
 * In-memory {@see LoggerInterface} that records every call as a (level,
 * message, context) tuple so tests can assert on the cron's log output
 * without driving a real Monolog handler.
 */
final class RecordingLogger implements LoggerInterface
{
    /** @var list<array{level:string,message:string,context:array<string,mixed>}> */
    public array $lines = [];

    public function emergency($message, array $context = []): void
    {
        $this->record('emergency', (string) $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->record('alert', (string) $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->record('critical', (string) $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->record('error', (string) $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->record('warning', (string) $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->record('notice', (string) $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->record('info', (string) $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->record('debug', (string) $message, $context);
    }

    public function log($level, $message, array $context = []): void
    {
        $this->record((string) $level, (string) $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function record(string $level, string $message, array $context): void
    {
        $this->lines[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }
}
