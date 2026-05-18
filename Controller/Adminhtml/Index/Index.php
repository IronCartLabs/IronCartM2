<?php

/**
 * IronCart_Scan — default admin route alias.
 *
 * Magento expands a bare admin frontName URL (e.g.
 * `/admin/ironcartscan/`) to `<frontName>/index/index` before
 * dispatching to a controller. Without this class that expansion
 * produces a 404 because the v1 landing controller lives at
 * `IronCart\Scan\Controller\Adminhtml\Scans\Index` (route
 * `ironcartscan/scans/index`), not under `Index/Index`.
 *
 * The 404 was surfaced by the bug report in issue #120: clicking the
 * "Run Scan Now" affordance from a fresh v1.3.0 install lands at
 * `/admin/ironcartscan/index/index/key/<formkey>/` and Magento
 * renders its admin 404 page because no controller resolves there.
 * Stale admin bookmarks pointing at the same path hit the same wall.
 *
 * This controller is a thin 302 alias to the canonical landing page
 * at `ironcartscan/scans/index` so that:
 *
 *   1. Bare `/admin/ironcartscan/` resolves cleanly (Magento's default
 *      action/controller fallback finds us instead of 404ing).
 *   2. Legacy / stale bookmarks to `.../index/index/` keep working.
 *   3. Future menu / button regressions that point at a bare route
 *      degrade to a redirect, not a hard 404.
 *
 * Gated on the read-only `IronCart_Scan::view` ACL — the redirect
 * lands on the listing page, which itself requires `::view`, so
 * gating here lets Magento render a 403 instead of leaking the
 * redirect to admins who lack the resource.
 *
 * Read-only: no DB writes, no outbound network calls. Consistent
 * with the module-wide v0 contract documented in `Controller/Adminhtml/Scans/Run.php`.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Controller\Adminhtml\Index;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

class Index extends Action implements HttpGetActionInterface
{
    /**
     * ACL resource required to follow the redirect.
     *
     * Mirrors `IronCart\Scan\Controller\Adminhtml\Scans\Index::ADMIN_RESOURCE`
     * so admins without `::view` see Magento's 403, not the destination.
     */
    public const ADMIN_RESOURCE = 'IronCart_Scan::view';

    /**
     * Canonical landing route the alias redirects to.
     */
    public const CANONICAL_PATH = 'ironcartscan/scans/index';

    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    /**
     * 302-redirect to the canonical landing page.
     */
    public function execute(): ResultInterface
    {
        /** @var Redirect $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $result->setPath(self::CANONICAL_PATH);

        return $result;
    }
}
