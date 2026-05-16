<?php

/**
 * IronCart_Scan — JSON status endpoint for an in-flight scan run.
 *
 * Polled by the `ironcartscan_run_listing` toolbar's "Run scan now"
 * JS module every 2s for any row whose status has not reached a
 * terminal state (succeeded / failed). The listing grid reload is
 * driven separately via uiRegistry — this endpoint only answers the
 * "did this one run flip yet?" question for a single id.
 *
 * Read-only by contract: this controller performs no DB writes and
 * no outbound network calls. It reads one `ironcart_scan_run` row and
 * decodes its `summary_json` blob.
 *
 * Security shape:
 *   - ACL: `IronCart_Scan::view` — polling is a read, not a run, so
 *     it gates on the lower-privilege resource. An admin who can see
 *     the listing must also be able to poll rows in it.
 *   - CSRF: not required for GET reads in Magento admin (the form
 *     key applies to state-changing verbs).
 *
 * Response shape on success (HTTP 200):
 *   {
 *     "runId":      <int>,
 *     "status":     "queued"|"running"|"succeeded"|"failed",
 *     "startedAt":  "<datetime>"|null,
 *     "finishedAt": "<datetime>"|null,
 *     "summary":    <object>           // decoded summary_json, or {}
 *   }
 *
 * Missing / invalid id → HTTP 404 with { "error": "..." }.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Controller\Adminhtml\Scans;

use IronCart\Scan\Model\ResourceModel\ScanRun as ScanRunResource;
use IronCart\Scan\Model\ScanRun;
use IronCart\Scan\Model\ScanRunFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Throwable;

class Status extends Action implements HttpGetActionInterface
{
    /**
     * ACL resource — read-only listing access. The matching write-side
     * controller {@see Run} gates on `IronCart_Scan::run`.
     */
    public const ADMIN_RESOURCE = 'IronCart_Scan::view';

    public function __construct(
        Context $context,
        private readonly ScanRunFactory $scanRunFactory,
        private readonly ScanRunResource $scanRunResource,
        private readonly Json $serializer,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Look up the run by id and return its current status envelope.
     */
    public function execute(): ResultInterface
    {
        /** @var JsonResult $result */
        $result = $this->jsonFactory->create();

        $runId = (int)$this->getRequest()->getParam('id', 0);
        if ($runId <= 0) {
            return $result
                ->setHttpResponseCode(404)
                ->setData(['error' => 'Missing or invalid id.']);
        }

        $run = $this->scanRunFactory->create();
        $this->scanRunResource->load($run, $runId);

        if (!$run->getId()) {
            return $result
                ->setHttpResponseCode(404)
                ->setData(['error' => 'Scan run not found.']);
        }

        return $result->setData([
            'runId'      => (int)$run->getId(),
            'status'     => (string)$run->getStatus(),
            'startedAt'  => $run->getStartedAt(),
            'finishedAt' => $run->getFinishedAt(),
            'summary'    => $this->decodeSummary($run),
        ]);
    }

    /**
     * Decode `summary_json` to an array. Defensive: malformed JSON or
     * a missing column collapses to `[]` so the JSON result encodes
     * `{}` rather than `null` (keeps the wire shape stable for clients).
     *
     * @return array<string,mixed>
     */
    private function decodeSummary(ScanRun $run): array
    {
        $summaryJson = $run->getSummaryJson();
        if (!is_string($summaryJson) || $summaryJson === '') {
            return [];
        }

        try {
            $decoded = $this->serializer->unserialize($summaryJson);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
