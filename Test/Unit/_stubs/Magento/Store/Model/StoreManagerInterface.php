<?php

/**
 * Unit-test stub of Magento\Store\Model\StoreManagerInterface (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Store\Model;

interface StoreManagerInterface
{
    public const CONTEXT_STORE = 'store';
    public const PARAM_NAME = '___store';

    /**
     * @param bool $value
     * @return void
     */
    public function setIsSingleStoreModeAllowed($value);

    /**
     * @return bool
     */
    public function hasSingleStore();

    /**
     * @return bool
     */
    public function isSingleStoreMode();

    /**
     * @param null|string|bool|int $storeId
     * @return mixed
     */
    public function getStore($storeId = null);

    /**
     * @param bool $withDefault
     * @param bool $codeKey
     * @return array<int|string, mixed>
     */
    public function getStores($withDefault = false, $codeKey = false);

    /**
     * @param null|bool|int|string $websiteId
     * @return mixed
     */
    public function getWebsite($websiteId = null);

    /**
     * @param bool $withDefault
     * @param bool $codeKey
     * @return array<int|string, mixed>
     */
    public function getWebsites($withDefault = false, $codeKey = false);

    /**
     * @return void
     */
    public function reinitStores();

    /**
     * @return mixed
     */
    public function getDefaultStoreView();

    /**
     * @param null|int|string $groupId
     * @return mixed
     */
    public function getGroup($groupId = null);

    /**
     * @param bool $withDefault
     * @return array<int|string, mixed>
     */
    public function getGroups($withDefault = false);

    /**
     * @param string|int|mixed $store
     * @return void
     */
    public function setCurrentStore($store);
}
