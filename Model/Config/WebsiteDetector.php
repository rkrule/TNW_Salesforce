<?php

namespace TNW\Salesforce\Model\Config;

/**
 * Class Owner
 * @package TNW\Salesforce\Model\Config\Source\Customer
 */
class WebsiteDetector
{

    /** @var \Magento\Store\Model\App\Emulation */
    protected $storeEmulator;

    /** @var \Magento\Store\Model\StoreManagerInterface  */
    protected $storeManager;

    /** @var array  */
    protected $websiteStores = [];

    /**
     * Request object
     *
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $request;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var \Magento\Store\Model\WebsiteFactory
     */
    protected $websiteFactory;

    /**
     * @var \Magento\Framework\App\State
     */
    protected $appState;

    /**
     * WebsiteDetector constructor.
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Magento\Store\Model\App\Emulation $storeEmulator
     * @param \Magento\Store\Model\WebsiteFactory $websiteFactory
     * @param \Magento\Framework\App\State $appState
     * @param \Magento\Framework\App\ResourceConnection $resourceConnection
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     */
    public function __construct(
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Store\Model\App\Emulation $storeEmulator,
        \Magento\Store\Model\WebsiteFactory $websiteFactory,
        \Magento\Framework\App\State $appState,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \Magento\Store\Model\StoreManagerInterface $storeManager
    )
    {
        $this->request = $request;
        $this->storeEmulator = $storeEmulator;
        $this->websiteFactory = $websiteFactory;
        $this->appState = $appState;
        $this->resourceConnection = $resourceConnection;
        $this->storeManager = $storeManager;
    }

    /**
     * @return mixed
     */
    public function getWebsiteFromRequest()
    {
        $websiteId = $this->request->getParam(
            'website'
        );

        return $websiteId;
    }

    /**
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function isAdminArea()
    {
        return ($this->appState->getAreaCode() == \Magento\Framework\App\Area::AREA_ADMINHTML);
    }

    /**
     * @return \Magento\Store\Api\Data\WebsiteInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getCurrentStoreWebsite()
    {
        return $this->storeManager->getWebsite();
    }

    /**
     * @param $websiteId int
     * @return int|mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function detectCurrentWebsite($websiteId = null)
    {
        if (empty($websiteId)) {
            if ($this->isAdminArea()) {
                $websiteId = $this->getWebsiteFromRequest();
            }

            if (empty($websiteId)) {
                $websiteId = $this->getCurrentStoreWebsite()->getId();
            }
        }

        return $websiteId;
    }

    /**
     * @param null $websiteId
     * @return int
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getStroreIdByWebsite($websiteId = null)
    {
        $websiteId = $this->detectCurrentWebsite($websiteId);

        if (!$this->websiteStores) {

            $website = $this->websiteFactory->create();

            $this->websiteStores = $website->getDefaultStoresSelect(true);

            $this->websiteStores =  $this->resourceConnection->getConnection()->fetchPairs($website->getDefaultStoresSelect(true));
        }

        return $this->websiteStores[$websiteId];
    }
}