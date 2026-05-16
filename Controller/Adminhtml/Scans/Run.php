<?php

/**
 * IronCart_Scan — POST controller that enqueues a scan run.
 *
 * Wired to the "Run scan now" toolbar button on the
 * `ironcartscan_run_listing` UI Component. Creates a queued
 * `ironcart_scan_run` row via {@see ScanRunPublisher}, publishes the
 * `ironcart.scan.run` topic so the DB queue consumer picks it up, and
 * returns a JSON `{ runId, status: "queued" }` payload.
 *
 * Security shape:
 *   - ACL: `IronCart_Scan::run` — admin role must have the explicit
 *     run permission, not just the read-only `::view`.
 *   - CSRF: enforced by Magento's admin action framework via the
 *     `HttpPostActionInterface` + admin form_key check. No opt-out;
 *     the listing JS posts the value of `window.FORM_KEY`.
 *   - The published payload carries only `admin:<id>` for traceability.
 *     The username is intentionally omitted (PII / log-leak guard).
 *
 * Read-only invariant: this endpoint writes exactly one row to
 * `ironcart_scan_run` and publishes one queue message. It performs no
 * outbound network calls — consistent with the module-wide v0 contract.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Controller\Adminhtml\Scans;

use IronCart\Scan\Model\ScanRun;
use IronCart\Scan\Model\ScanRunPublisher;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class Run extends Action implements HttpPostActionInterface
{
    /**
     * ACL resource required to enqueue a scan run. Distinct from
     * `IronCart_Scan::view` (read-only listing access) — admins can be
     * granted view without run.
     */
    public const ADMIN_RESOURCE = 'IronCart_Scan::run';

    public function __construct(
        Context $context,
        private readonly ScanRunPublisher $publisher,
        private readonly AuthSession $authSession,
        private readonly JsonFactory $jsonFactory,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Enqueue a new scan run and return the new run id.
     *
     * Response shape (HTTP 200):
     *   { "runId": <int>, "status": "queued" }
     *
     * Failure shape (HTTP 500):
     *   { "error": "<message>" }
     *
     * The 500 path covers DB-write failures and publisher serialisation
     * errors. ACL / CSRF rejections never reach this method — Magento's
     * admin action framework short-circuits with a redirect or 403 first.
     */
    public function execute(): ResultInterface
    {
        /** @var JsonResult $result */
        $result = $this->jsonFactory->create();

        try {
            $runId = $this->publisher->publish($this->buildTriggeredBy());
        } catch (Throwable $e) {
            $this->logger->error(
                'IronCart_Scan: failed to enqueue scan run from admin button',
                ['exception' => $e]
            );
            return $result
                ->setHttpResponseCode(500)
                ->setData(['error' => 'Could not enqueue scan run.']);
        }

        return $result->setData([
            'runId'  => $runId,
            'status' => ScanRun::STATUS_QUEUED,
        ]);
    }

    /**
     * Build the abstract `admin:<id>` marker that travels with the run.
     *
     * Falls back to literal `admin` (no id) if the admin session has been
     * invalidated between CSRF check and controller dispatch — vanishingly
     * unlikely but the publisher requires a non-empty string. The username
     * is never carried (PII guard).
     */
    private function buildTriggeredBy(): string
    {
        $user = $this->authSession->getUser();
        $userId = $user !== null ? (int)$user->getId() : 0;

        return $userId > 0 ? 'admin:' . $userId : 'admin';
    }
}
