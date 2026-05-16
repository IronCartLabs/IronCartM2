<?php

/**
 * IronCart_Scan — ScanRun model.
 *
 * Thin wrapper over the `ironcart_scan_run` row added by the declarative
 * schema in #25. Stays AbstractModel-based (not an Entity-Manager
 * repository) because v1 only needs row-level CRUD: status transitions
 * driven by ScanRunConsumer and grid reads driven by the admin UI
 * data provider in #28.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 *
 * @method int|null    getEntityId()
 * @method string      getStatus()
 * @method string      getTriggeredBy()
 * @method string|null getStartedAt()
 * @method string|null getFinishedAt()
 * @method string|null getSummaryJson()
 * @method string|null getCreatedAt()
 * @method string|null getUpdatedAt()
 * @method $this       setStatus(string $status)
 * @method $this       setTriggeredBy(string $triggeredBy)
 * @method $this       setStartedAt(?string $startedAt)
 * @method $this       setFinishedAt(?string $finishedAt)
 * @method $this       setSummaryJson(?string $summaryJson)
 */

declare(strict_types=1);

namespace IronCart\Scan\Model;

use Magento\Framework\Model\AbstractModel;

class ScanRun extends AbstractModel
{
    public const STATUS_QUEUED    = 'queued';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED    = 'failed';

    /**
     * Triggered-by markers — admin user ids land as `admin:<id>` but the
     * literal `cli` and `cron` strings are reserved for non-admin sources.
     */
    public const TRIGGER_CLI  = 'cli';
    public const TRIGGER_CRON = 'cron';

    protected function _construct(): void
    {
        $this->_init(\IronCart\Scan\Model\ResourceModel\ScanRun::class);
    }
}
