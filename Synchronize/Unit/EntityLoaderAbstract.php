<?php
namespace TNW\Salesforce\Synchronize\Unit;

/**
 * Mapping Entity Loader
 */
abstract class EntityLoaderAbstract
{
    /**
     * @var \TNW\Salesforce\Model\Entity\SalesforceIdStorage
     */
    private $salesforceIdStorage;

    /**
     * MappingEntityLoaderAbstract constructor.
     * @param \TNW\Salesforce\Model\Entity\SalesforceIdStorage $salesforceIdStorage
     */
    public function __construct(
        \TNW\Salesforce\Model\Entity\SalesforceIdStorage $salesforceIdStorage = null
    ) {
        $this->salesforceIdStorage = $salesforceIdStorage;
    }

    /**
     * Load
     *
     * @param \Magento\Framework\Model\AbstractModel $entity
     * @return \Magento\Framework\Model\AbstractModel
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function get($entity)
    {
        $subEntity = $this->load($entity);
        if (null !== $this->salesforceIdStorage && null !== $subEntity->getId()) {
            $this->salesforceIdStorage->load($subEntity, $entity->getData('config_website'));
        }

        return $subEntity;
    }

    /**
     * Load
     *
     * @param \Magento\Framework\Model\AbstractModel $entity
     * @return \Magento\Framework\Model\AbstractModel
     */
    abstract public function load($entity);
}