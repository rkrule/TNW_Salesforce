<?php
namespace TNW\Salesforce\Synchronize;

use Magento\Framework\Stdlib\DateTime\Timezone;
use TNW\Salesforce\Model;
use TNW\Salesforce\Model\ResourceModel\Queue\Collection;
use TNW\Salesforce\Synchronize\Exception as SalesforceException;
use Zend_Db_Expr;

/**
 * Queue
 */
class Queue
{
    /**
     * @var Group[]
     */
    private $groups;

    /**
     * @var string[]
     */
    private $phases;

    /**
     * @var Model\Config
     */
    private $salesforceConfig;

    /**
     * @var Model\ResourceModel\Queue
     */
    private $resourceQueue;

    /**
     * @var Model\Logger\Processor\UidProcessor
     */
    private $uidProcessor;

    /**
     * @var Timezone
     */
    private $timezone;

    /** @var [] */
    private $sortGroups;

    /** @var Queue\PushMqMessage  */
    private $pushMqMessage;

    /**
     * @var boolean
     */
    private $isCheck = false;

    /**
     * Queue constructor.
     * @param Group[] $groups
     * @param array $phases
     * @param Model\Config $salesforceConfig
     * @param Model\ResourceModel\Queue $resourceQueue
     * @param Model\Logger\Processor\UidProcessor $uidProcessor
     * @param Timezone $timezone
     * @param bool $isCheck
     */
    public function __construct(
        array $groups,
        array $phases,
        Model\Config $salesforceConfig,
        Model\ResourceModel\Queue $resourceQueue,
        Model\Logger\Processor\UidProcessor $uidProcessor,
        Timezone $timezone,
        \TNW\Salesforce\Synchronize\Queue\PushMqMessage $pushMqMessage,
        $isCheck = false
    ) {
        $this->groups = $groups;
        $this->phases = array_filter($phases);
        $this->salesforceConfig = $salesforceConfig;
        $this->resourceQueue = $resourceQueue;
        $this->uidProcessor = $uidProcessor;
        $this->timezone = $timezone;
        $this->pushMqMessage = $pushMqMessage;
        $this->setIsCheck($isCheck);
    }

    /**
     * Synchronize
     *
     * @param $collection Collection
     * @param $websiteId
     * @param array $syncJobs
     */
    public function synchronize($collection, $websiteId, $syncJobs = [])
    {
        // Collection Clear
        $collection->clear();

        // Filter To Website
        $collection->addFilterToWebsiteId($websiteId);

        // Check not empty
        if ($collection->getSize() === 0) {
            return;
        }

        // Collection Clear
        $collection->clear();

        ksort($this->phases);

        foreach ($this->sortGroup($syncJobs) as $groupKey => $group) {
            // refresh uid
            $this->uidProcessor->refresh();

            foreach ($this->phases as $phase) {
                $lockCollection = clone $collection;
                $lockCollection->addFilterToCode($group->code());
                $lockCollection->addFilterToStatus($phase['startStatus']);
                $lockCollection->addFilterToNotTransactionUid($this->uidProcessor->uid());
                $lockCollection->addFilterDependent();

                $lockData = [
                    'status' => $phase['processStatus'],
                    'transaction_uid' => $this->uidProcessor->uid(),
                    'identify' => new Zend_Db_Expr('queue_id')
                ];

                if ($phase['startStatus'] == Model\Queue::STATUS_NEW) {
                    $lockData['sync_at'] = $this->timezone->date()->format('c');
                }

                // Mark work
                $countUpdate = $lockCollection->updateLock($lockData);

                if (0 === $countUpdate) {
                    continue;
                }

                $groupCollection = clone $collection;
                $groupCollection->addFilterToStatus($phase['processStatus']);
                $groupCollection->addFilterToTransactionUid($this->uidProcessor->uid());

                $groupCollection->setPageSize($this->salesforceConfig->getPageSizeFromMagento());

                $lastPageNumber = (int)$groupCollection->getLastPageNumber();
                for ($i = 1; $i <= $lastPageNumber; $i++) {
                    $groupCollection->clear();

                    $group->messageDebug(
                        'Start job "%s", phase "%s" for website %s',
                        $group->code(),
                        $phase['phaseName'],
                        $websiteId
                    );

                    try {
                            $groupCollection->each('incSyncAttempt');
                            $groupCollection->each('setData', ['_is_last_page', $lastPageNumber === $i]);
                            $group->synchronize($groupCollection->getItems());

                    } catch (\Exception $e) {

                        if ($e instanceof SalesforceException) {
                            $status = $e->getQueueStatus();
                        } else {
                            $status = $phase['errorStatus'];
                        }

                        $groupCollection->each('addData', [[
                            'status' => $status,
                            'message' => $e->getMessage()
                        ]]);

                        $group->messageError($e);

                        if (!empty($groupCollection->getFirstItem())) {
                            $this->pushMqMessage->sendMessage($groupCollection->getFirstItem()->getSyncType());
                        }
                    }

                    $group->messageDebug(
                        'Stop job "%s", phase "%s" for website %s',
                        $group->code(),
                        $phase['phaseName'],
                        $websiteId
                    );

                    // Save change status
                    $groupCollection->each([$groupCollection->getResource(), 'save']);

                    gc_collect_cycles();
                }
            }
        }

        return;
    }

    /**
     * Sort Group
     *
     * @return Group[]
     */
    public function sortGroup($syncJobs = null)
    {
        $addGroup = function (array &$sortGroups, Group $group) use (&$addGroup, &$description) {
            $description[] = sprintf('%s;', $group->code());
            foreach ($this->resourceQueue->getDependenceByCode($group->code()) as $type => $dependent) {
                if (empty($this->groups[$dependent])) {
                    continue;
                }

                $description[] = sprintf('%s <- %s;', $group->code(), $dependent);

                if ($group->code() == $dependent) {
                    continue;
                }
                if (isset($sortGroups[$dependent])) {
                    continue;
                }

                $addGroup($sortGroups, $this->groups[$dependent]);
            }

            $sortGroups[$group->code()] = $group;
        };

        if (empty($this->sortGroups)) {

            $sortGroups = [];
//        $i=0;

            foreach ($this->groups as $unit) {
                $description = [
                    sprintf('digraph %s {', $unit->code())
                ];

                $description[] = sprintf('label = "process %s";', $unit->code());

                $addGroup($sortGroups, $unit);

                $description[] = '}';
//            file_put_contents( 'dot/' . $unit->code() . '.dot', implode("\n", $description));
            }
            $this->sortGroups = $sortGroups;
        } else {
            $sortGroups = $this->sortGroups;
        }

        if (!empty($syncJobs)) {
            foreach ($sortGroups as $key => $group) {
                if (!in_array($key, $syncJobs)) {
                    unset($sortGroups[$key]);
                }
            }
        }

        return $sortGroups;
    }

    /**
     * @param bool $isCheck
     */
    public function setIsCheck(bool $isCheck)
    {
        $this->isCheck = $isCheck;
    }

    /**
     * @return bool
     */
    public function isCheck()
    {
        return $this->isCheck;
    }
}
