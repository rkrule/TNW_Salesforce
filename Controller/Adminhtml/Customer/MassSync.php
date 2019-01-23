<?php

namespace TNW\Salesforce\Controller\Adminhtml\Customer;

/**
 * Class MassSync
 * @package Magento\Customer\Controller\Adminhtml\Index
 */
class MassSync extends \Magento\Backend\App\Action
{
    /**
     * @var \TNW\Salesforce\Synchronize\Queue\Entity
     */
    private $entityCustomer;

    /**
     * @var \Magento\Ui\Component\MassAction\Filter
     */
    private $massActionFilter;

    /**
     * @var \Magento\Customer\Model\ResourceModel\Customer\CollectionFactory
     */
    private $collectionFactory;

    /**
     * MassSync constructor.
     * @param \Magento\Backend\App\Action\Context $context
     * @param \TNW\Salesforce\Synchronize\Queue\Entity $entityCustomer
     * @param \Magento\Ui\Component\MassAction\Filter $massActionFilter
     * @param \Magento\Customer\Model\ResourceModel\Customer\CollectionFactory $collectionFactory
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \TNW\Salesforce\Synchronize\Queue\Entity $entityCustomer,
        \Magento\Ui\Component\MassAction\Filter $massActionFilter,
        \Magento\Customer\Model\ResourceModel\Customer\CollectionFactory $collectionFactory
    ) {
        parent::__construct($context);
        $this->entityCustomer = $entityCustomer;
        $this->massActionFilter = $massActionFilter;
        $this->collectionFactory = $collectionFactory;
    }

    /**
     * Dispatch request
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        try {
            $entityIds = $this->massActionFilter
                ->getCollection($this->collectionFactory->create())
                ->getAllIds();

            $this->entityCustomer->addToQueue($entityIds);
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e);
        }

        return $this->resultRedirectFactory->create()
            ->setPath('customer/index');
    }
}
