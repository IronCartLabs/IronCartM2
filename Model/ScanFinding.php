<?php

/**
 * IronCart_Scan — ScanFinding model.
 *
 * One row per finding emitted during a scan run. Owned by ScanRun via the
 * `scan_run_id` FK (cascade delete).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 *
 * @method int|null    getEntityId()
 * @method int         getScanRunId()
 * @method string      getCheckId()
 * @method string      getSeverity()
 * @method string      getTitle()
 * @method string|null getDetail()
 * @method string|null getEvidenceJson()
 * @method string|null getCreatedAt()
 * @method $this       setScanRunId(int $scanRunId)
 * @method $this       setCheckId(string $checkId)
 * @method $this       setSeverity(string $severity)
 * @method $this       setTitle(string $title)
 * @method $this       setDetail(?string $detail)
 * @method $this       setEvidenceJson(?string $evidenceJson)
 */

declare(strict_types=1);

namespace IronCart\Scan\Model;

use Magento\Framework\Model\AbstractModel;

class ScanFinding extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(\IronCart\Scan\Model\ResourceModel\ScanFinding::class);
    }
}
