<?php

/**
 * IronCart_Scan — ConsumerStalledMessage copy tests (issue #158).
 *
 * Lives under Test/Unit/Report so the unit CI cell loads it (see ci.yml
 * — only Test/Unit/Report is enumerated in the override phpunit.xml
 * because the cell runs without `magento/framework` on the classpath).
 *
 * {@see \IronCart\Scan\Model\Notification\ConsumerStalledMessage}
 * implements `Magento\Framework\Notification\MessageInterface`, so we
 * cannot instantiate it here — autoloading the class itself would pull
 * in the framework. Instead this test asserts against the file content
 * directly. That is sufficient for the AC of issue #158, which is a
 * pure copy change:
 *
 *   - the stale `consumers_runner` cron-group recommendation must be
 *     gone from the operator-facing notice text and from the docblock
 *     on the class;
 *   - the replacement copy must point operators at
 *     `bin/magento cron:install` and the module's own
 *     `ironcart_scan_consumer_drain` cron job (introduced in PR #143);
 *   - the existing supervisor path (`queue:consumers:start
 *     ironcartScanRunConsumer`) must still be named, since both
 *     remediation paths are supported (see README §"Running scans
 *     asynchronously").
 *
 * Full end-to-end coverage of the message rendering through Magento's
 * notice list is exercised by the integration job inside the
 * docker-compose Magento sandbox; this test guards only the operator-
 * facing copy regression that #158 fixes.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Report;

use PHPUnit\Framework\TestCase;

class ConsumerStalledMessageTest extends TestCase
{
    /**
     * Module root, resolved relative to this test file. Three levels up
     * from `Test/Unit/Report/` lands at the module root regardless of
     * whether the CI runner ran from the repo root or the Magento sandbox.
     */
    private const MODULE_ROOT_OFFSET = '/../../../';

    private string $source = '';

    protected function setUp(): void
    {
        $path = __DIR__ . self::MODULE_ROOT_OFFSET . 'Model/Notification/ConsumerStalledMessage.php';
        $abs = realpath($path);
        $this->assertNotFalse(
            $abs,
            sprintf('Expected ConsumerStalledMessage.php at %s', $path)
        );
        $contents = file_get_contents($abs);
        $this->assertNotFalse(
            $contents,
            sprintf('Could not read %s', $abs)
        );
        $this->source = $contents;
    }

    public function testNoticeTextNoLongerMentionsConsumersRunner(): void
    {
        // Post-#143 the module owns its own drain cron job
        // (`ironcart_scan_consumer_drain`). The legacy
        // `cron_consumers_runner` env.php edit is no longer the
        // recommended path and would cause double-draining (see #158
        // and the README's "Running scans asynchronously" section).
        $this->assertStringNotContainsString(
            'consumers_runner',
            $this->source,
            'ConsumerStalledMessage must not mention the legacy consumers_runner cron group anywhere — '
            . 'both the operator notice text and the class docblock were updated by #158.'
        );
    }

    public function testNoticeTextDirectsOperatorAtCronInstall(): void
    {
        // The remediation path post-#143 is "verify Magento's cron is
        // running, which drives the module's own drain job". The
        // notice should name `bin/magento cron:install` so the
        // operator does not need to round-trip to the README.
        $this->assertStringContainsString(
            'bin/magento cron:install',
            $this->source,
            'ConsumerStalledMessage::getText() must reference `bin/magento cron:install` post-#158.'
        );
    }

    public function testNoticeTextNamesTheModuleDrainCronJob(): void
    {
        // Naming the cron job lets the operator grep their cron
        // logs for `ironcart_scan_consumer_drain` to confirm the
        // drain is firing.
        $this->assertStringContainsString(
            'ironcart_scan_consumer_drain',
            $this->source,
            'ConsumerStalledMessage::getText() must name the module\'s own drain cron job '
            . '(`ironcart_scan_consumer_drain`) so operators can grep cron logs for it.'
        );
    }

    public function testNoticeTextStillNamesTheSupervisorPath(): void
    {
        // The dedicated-supervisor path (`queue:consumers:start
        // ironcartScanRunConsumer`) is still supported per README
        // §"Already running a dedicated consumer supervisor?". The
        // notice must continue to name it as an alternative.
        $this->assertStringContainsString(
            'queue:consumers:start',
            $this->source,
            'ConsumerStalledMessage::getText() must still name the supervisor path as an alternative remediation.'
        );
        $this->assertStringContainsString(
            'ironcartScanRunConsumer',
            $this->source,
            'ConsumerStalledMessage must still name the consumer handle so the operator can copy-paste it.'
        );
    }

    public function testNoticeTextStillLinksTheReadmeWalkthrough(): void
    {
        // The README section is the canonical operator walkthrough.
        // The notice should keep linking to it.
        $this->assertStringContainsString(
            'https://github.com/IronCartLabs/IronCartM2#running-scans-asynchronously',
            $this->source,
            'ConsumerStalledMessage::getText() must continue to link the README walkthrough.'
        );
    }
}
