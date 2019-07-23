<?php
namespace TNW\Salesforce\Synchronize\Unit;

use TNW\Salesforce\Synchronize;
use TNW\Salesforce\Model;

/**
 * Mapping Abstract
 */
class Mapping extends Synchronize\Unit\UnitAbstract
{
    const PARENT_ENTITY = '__parent_entity';

    /**
     * @var string
     */
    private $load;

    /**
     * @var string
     */
    private $lookup;

    /**
     * @var string
     */
    private $objectType;

    /**
     * @var \TNW\Salesforce\Model\ResourceModel\Mapper\CollectionFactory
     */
    private $mapperCollectionFactory;

    /**
     * @var IdentificationInterface
     */
    protected $identification;

    /**
     * MappingAbstract constructor.
     *
     * @param string $name
     * @param string $load
     * @param string $lookup
     * @param string $objectType
     * @param Synchronize\Units $units
     * @param Synchronize\Group $group
     * @param IdentificationInterface $identification
     * @param Model\ResourceModel\Mapper\CollectionFactory $mapperCollectionFactory
     * @param array $dependents
     */
    public function __construct(
        $name,
        $load,
        $lookup,
        $objectType,
        Synchronize\Units $units,
        Synchronize\Group $group,
        Synchronize\Unit\IdentificationInterface $identification,
        Model\ResourceModel\Mapper\CollectionFactory $mapperCollectionFactory,
        array $dependents = []
    ) {
        parent::__construct($name, $units, $group, array_merge($dependents, [$load, $lookup]));
        $this->load = $load;
        $this->objectType = $objectType;
        $this->identification = $identification;
        $this->mapperCollectionFactory = $mapperCollectionFactory;
        $this->lookup = $lookup;
    }

    /**
     * @inheritdoc
     */
    public function description()
    {
        if (empty($this->objectType)) {
            return __('System mapping');
        }

        return __('Mapping for %1', $this->objectType);
    }

    /**
     * Lookup
     *
     * @return LookupAbstract|UnitInterface
     */
    public function lookup()
    {
        return $this->unit($this->lookup);
    }

    /**
     * Load
     *
     * @return Load|UnitInterface
     */
    public function load()
    {
        return $this->unit($this->load);
    }

    /**
     * Object Type
     *
     * @return string
     */
    public function objectType()
    {
        return $this->objectType;
    }

    /**
     * Process
     *
     * @throws \OutOfBoundsException
     * @throws \InvalidArgumentException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function process()
    {
        $message = [];
        foreach ($this->entities() as $entity) {
            $mappers = $this->mappers($entity);

            $count = 0;
            $message[] = __(
                "Entity %1 mapping:\n%2",
                $this->identification->printEntity($entity),
                implode("\n", $mappers->walk(function (Model\Mapper $mapper) use (&$count) {
                    $count++;

                    $message = "{$count}) ";
                    $message .= strcasecmp($mapper->getAttributeType(), 'custom') !== 0
                        ? "{$mapper->getMagentoEntityType()}::" : 'custom::';
                    $message .= "{$mapper->getMagentoAttributeName()} -> {$mapper->getSalesforceAttributeName()}";

                    if (!is_numeric($mapper->getId())) {
                        $message .= ' (is system mapping)';
                    }

                    return $message;
                }))
            );

            $this->cache[$entity] = $this->generateObject($entity, $mappers);
        }

        if ($this->cache->count() === 0) {
            $this->group()->messageDebug('Mapping SKIPPED ...');
        }

        if (!empty($message)) {
            $this->group()->messageDebug(implode("\n\n", $message));
        }
    }

    /**
     * Generate Object
     *
     * @param \Magento\Framework\Model\AbstractModel $entity
     * @param Model\ResourceModel\Mapper\Collection $mappers
     * @return array
     * @throws \OutOfBoundsException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function generateObject($entity, Model\ResourceModel\Mapper\Collection $mappers)
    {
        $object = [];

        foreach ($mappers as $mapper) {
            $value = $this->value($entity, $mapper);
            if (null === $value) {
                continue;
            }

            $object[$mapper->getSalesforceAttributeName()] = $value;
        }

        $salesforce = $this->findSalesforce($entity);
        if (!empty($salesforce)) {
            $object['Id'] = $salesforce;
        }

        return $object;
    }

    /**
     * Value
     *
     * @param \Magento\Framework\Model\AbstractModel $entity
     * @param Model\Mapper $mapper
     * @return mixed|null
     */
    public function value($entity, $mapper)
    {
        $value = null;
        switch ($mapper->getAttributeType()) {
            case 'custom':
                $value = $mapper->getDefaultValue();
                break;

            default:
                $subEntity = $this->objectByEntityType($entity, $mapper->getMagentoEntityType());
                if (!$subEntity instanceof \Magento\Framework\DataObject) {
                    $this->group()->messageDebug(
                        'Object type "%s" not found. Entity: %s.',
                        $mapper->getMagentoEntityType(),
                        $this->identification->printEntity($entity)
                    );

                    break;
                }

                $subEntity->setData(self::PARENT_ENTITY, $entity);
                $value = $this->prepareValue($subEntity, $mapper->getMagentoAttributeName());
                break;
        }

        if (null === $value || (is_string($value) && '' === trim($value))) {
            $value = $this->defaultValue($entity, $mapper);
        }

        return $value;
    }

    /**
     * Find Salesforce
     *
     * @param \Magento\Framework\Model\AbstractModel $entity
     * @return mixed
     * @throws \OutOfBoundsException
     */
    public function findSalesforce($entity)
    {
        return $this->lookup()->get('%s/record/Id', $entity);
    }

    /**
     * Mappers
     *
     * @param \Magento\Framework\Model\AbstractModel $entity
     * @return Model\ResourceModel\Mapper\Collection
     */
    public function mappers($entity)
    {
        return $this->mapperCollectionFactory->create()
            ->addObjectToFilter($this->objectType)
            ->applyUniquenessByWebsite($this->load()->get('websiteIds/%s', $entity));
    }

    /**
     * Entities
     *
     * @return \Magento\Framework\Model\AbstractModel[]
     * @throws \OutOfBoundsException
     */
    protected function entities()
    {
        return array_filter($this->load()->get('entities'), [$this, 'filter']);
    }

    /**
     * Filter
     *
     * @param \Magento\Framework\Model\AbstractModel $entity
     * @return bool
     * @throws \OutOfBoundsException
     */
    protected function filter($entity)
    {
        return !in_array(true, array_map(function ($unit) use ($entity) {
            return $this->unit($unit)->skipped($entity);
        }, $this->dependents()), true);
    }

    /**
     * Object By Entity Type
     *
     * @param \Magento\Framework\Model\AbstractModel $entity
     * @param string $magentoEntityType
     * @return \Magento\Framework\Model\AbstractModel
     */
    protected function objectByEntityType($entity, $magentoEntityType)
    {
        return $this->load()->entityByType($entity, $magentoEntityType);
    }

    /**
     * Prepare Value
     *
     * @param \Magento\Framework\Model\AbstractModel $entity
     * @param string $attributeCode
     * @return mixed
     */
    public function prepareValue($entity, $attributeCode)
    {
        $value = $entity->getData($attributeCode);
        if (null === $value) {
            $method = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $attributeCode)));
            $value = $entity->{$method}();
        }

        switch (true) {
            case is_scalar($value) || $value === null:
                return $value;

            case is_array($value):
                foreach ($value as $v) {
                    if (is_scalar($v) || $v === null) {
                        continue;
                    }

                    break 2;
                }
                return implode('\n', $value);
        }

        $this->group()->messageError(
            'Incorrect value for mapping: scalar values supported only. Attribute code: "%s". Mapping class: "%s"',
            $attributeCode,
            get_class($this)
        );

        return null;
    }

    /**
     * Default Value
     *
     * @param \Magento\Framework\Model\AbstractModel $entity
     * @param Model\Mapper $mapper
     * @return mixed
     */
    protected function defaultValue($entity, $mapper)
    {
        return $mapper->getDefaultValue();
    }
}