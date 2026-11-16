<?php

/**
 * Unit-test stub of Magento\Framework\Indexer\IndexerInterface (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Framework\Indexer;

interface IndexerInterface
{
    /**
     * @return string
     */
    public function getId();

    /**
     * @return string
     */
    public function getViewId();

    /**
     * @return string
     */
    public function getActionClass();

    /**
     * @return string
     */
    public function getTitle();

    /**
     * @return string
     */
    public function getDescription();

    /**
     * @return array<mixed>
     */
    public function getFields();

    /**
     * @return array<mixed>
     */
    public function getSources();

    /**
     * @return array<mixed>
     */
    public function getHandlers();

    /**
     * @param string $indexerId
     * @return IndexerInterface
     */
    public function load($indexerId);

    /**
     * @return mixed
     */
    public function getView();

    /**
     * @return StateInterface
     */
    public function getState();

    /**
     * @param StateInterface $state
     * @return IndexerInterface
     */
    public function setState(StateInterface $state);

    /**
     * @return bool
     */
    public function isScheduled();

    /**
     * @param bool $scheduled
     * @return void
     */
    public function setScheduled($scheduled);

    /**
     * @return bool
     */
    public function isValid();

    /**
     * @return bool
     */
    public function isInvalid();

    /**
     * @return bool
     */
    public function isWorking();

    /**
     * @return void
     */
    public function invalidate();

    /**
     * @return string
     */
    public function getStatus();

    /**
     * @return string|false
     */
    public function getLatestUpdated();

    /**
     * @return void
     */
    public function reindexAll();

    /**
     * @param int $id
     * @return void
     */
    public function reindexRow($id);

    /**
     * @param int[] $ids
     * @return void
     */
    public function reindexList($ids);
}
