<?php
namespace TNW\Salesforce\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\Http;
use Magento\Framework\DataObject;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use TNW\Salesforce\Model\Config\WebsiteDetector;
use TNW\SForceEnterprise\Model\Config\Source\Synchronization\Mode;

/**
 * Class Config
 */
class Config extends DataObject
{
    const SFORCE_BASIC_PREFIX = 'tnw_mage_basic__';
    const SFORCE_ENTERPRISE_PREFIX = 'tnw_mage_enterp__';
    const SFORCE_WEBSITE_ID = 'Magento_Website__c';
    const SFORCE_MAGENTO_ID = 'Magento_ID__c';
    const BASE_DAY = 7;

    const MAPPING_WHEN_INSERT_ONLY = 'InsertOnly';

    const SYNC_MAX_ATTEMPT_COUNT_XML = 'tnwsforce_general/synchronization/max_attempt_count';

    /**
     * Base batch limit for simple sync
     */
    const SFORCE_BASE_UPDATE_LIMIT = 200;

    /**
     * Cron queue types
     */
    const DIRECT_SYNC_TYPE_REALTIME = 3;

    /** @comment Base batch limit for simple sync */
    const REALTIME_MAX_SYNC = 30;

    /** @var ScopeConfigInterface  */
    protected $scopeConfig;

    /** @var DirectoryList  */
    protected $directoryList;

    /** @var EncryptorInterface  */
    protected $encryptor;

    /** @var StoreManagerInterface  */
    protected $storeManager;

    /** @var WebsiteRepositoryInterface */
    protected $websiteRepository;

    /** @var array  */
    protected $websitesGrouppedByOrg = [];

    /**
     * @var array;
     */
    protected $isIntegrationActive = null;

    /** @var Http  */
    protected $request;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /** @var Config\WebsiteDetector  */
    protected $websiteDetector;

    /** @var array  */
    private $credentialsConfigPaths = [
        /** Org credentials */
        'tnwsforce_general/salesforce/active',
        'tnwsforce_general/salesforce/username',
        'tnwsforce_general/salesforce/password',
        'tnwsforce_general/salesforce/token',
        'tnwsforce_general/salesforce/wsdl'
    ];

    /**
     * Config constructor.
     * @param ScopeConfigInterface $scopeConfig
     * @param DirectoryList $directoryList
     * @param EncryptorInterface $encryptor
     * @param StoreManagerInterface $storeManager
     * @param WebsiteRepositoryInterface $websiteRepository
     * @param Http $request
     * @param Filesystem $filesystem
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        DirectoryList $directoryList,
        EncryptorInterface $encryptor,
        StoreManagerInterface $storeManager,
        WebsiteRepositoryInterface $websiteRepository,
        Http $request,
        Filesystem $filesystem,
        WebsiteDetector $websiteDetector
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->directoryList = $directoryList;
        $this->filesystem = $filesystem;
        $this->encryptor = $encryptor;
        $this->storeManager = $storeManager;
        $this->websiteRepository = $websiteRepository;
        $this->request = $request;
        $this->websiteDetector = $websiteDetector;

        parent::__construct();
    }

    /**
     * Base batch limit for simple sync
     *
     * @return int
     */
    public function getBaseUpdateLimit()
    {
        return self::SFORCE_BASE_UPDATE_LIMIT;
    }

    /**
     * Get magento product Id field name in Salesforce database
     *
     * @return string
     */
    public static function getMagentoIdField()
    {
        return self::SFORCE_BASIC_PREFIX . self::SFORCE_MAGENTO_ID;
    }

    /**
     * Get magento product Id field name in Salesforce database
     *
     * @return string
     */
    public function getWebsiteIdField()
    {
        return self::SFORCE_BASIC_PREFIX . self::SFORCE_WEBSITE_ID;
    }

    /**
     * Get TNW general status
     *
     * @param int|null $websiteId
     * @return string
     */
    public function getSalesforceStatus($websiteId = null)
    {
        return (bool)$this->getStoreConfig('tnwsforce_general/salesforce/active', $websiteId);
    }


    /**
     * Get Product Integration status
     *
     * @param int|null $websiteId
     * @return bool
     */
    public function getReverseSyncEnabled($websiteId = null)
    {
        return $this->getStoreConfig('tnwsforce_general/salesforce/active', $websiteId) == Mode::SYNC_MODE_BOTH;
    }

    /**
     * Get Salesforce endpoint location from config
     *
     * @param int|null $websiteId
     *
     * @return string
     */
    public function getSFDCLocationEndpoint($websiteId = null )
    {
	    return $this->getStoreConfig('tnswforce_general/salesforce/endpoint', $websiteId);
    }
    
    /**
     * Get Salesfoce username from config
     *
     * @param int|null $websiteId
     *
     * @return string
     */
    public function getSalesforceUsername($websiteId = null)
    {
        return $this->getStoreConfig('tnwsforce_general/salesforce/username', $websiteId);
    }

    /**
     * Get Salesfoce password from config
     *
     * @param int|null $websiteId
     * @return string
     */
    public function getSalesforcePassword($websiteId = null)
    {
        $password = $this->getStoreConfig('tnwsforce_general/salesforce/password', $websiteId);

        $decrypt = $this->encryptor->decrypt($password);
        if (!empty($decrypt)) {
            return $decrypt;
        }

        return $password;
    }

    /**
     * Get Salesfoce token from config
     *
     * @param int|null $websiteId
     * @return string
     */
    public function getSalesforceToken($websiteId = null)
    {
        $token = $this->getStoreConfig('tnwsforce_general/salesforce/token', $websiteId);

        $decrypt = $this->encryptor->decrypt($token);
        if (!empty($decrypt)) {
            return $decrypt;
        }

        return $token;
    }

    /**
     * Get Salesfoce wsdl path from config
     *
     * @param int|null $websiteId
     *
     * @return string
     * @throws FileSystemException
     */
    public function getSalesforceWsdl($websiteId = null)
    {
        $dir = $this->getStoreConfig('tnwsforce_general/salesforce/wsdl', $websiteId);

        if (strpos(trim($dir), '{var}') === 0) {
            $varDir = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
            return $varDir->getAbsolutePath(str_replace('{var}', '', $dir));
        }

        $root = $this->directoryList->getPath(DirectoryList::ROOT);

        return $root . DIRECTORY_SEPARATOR . $dir;
    }

    /**
     * @return bool
     */
    public function isDefaultOrg()
    {
        foreach ($this->credentialsConfigPaths as $configPath) {
            if ($this->getStoreConfig($configPath) != $this->scopeConfig->getValue($configPath)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return WebsiteInterface[]
     */
    public function getWebsites()
    {
        $result = $this->websiteRepository->getList();
        $adminWebsite = ['admin' => $result['admin']];
        unset($result['admin']);

        $result = $adminWebsite + $result;

        return $result;
    }

    /**
     * @return array
     */
    public function getWebsitesGrouppedByOrg()
    {
        if (empty($this->websitesGrouppedByOrg)) {
            $websites = $this->getWebsites();
            foreach ($websites as $website) {
                foreach ($websites as $websiteToCompare) {
                    $isSame = true;
                    foreach ($this->credentialsConfigPaths as $configPath) {
                        if ($this->scopeConfig->getValue($configPath, ScopeInterface::SCOPE_WEBSITE, $websiteToCompare->getId()) != $this->scopeConfig->getValue($configPath, ScopeInterface::SCOPE_WEBSITE, $website->getId())) {
                            $isSame = false;
                        }
                    }

                    /**
                     * first website with the same credentials was found
                     */
                    if ($isSame) {
                        $this->websitesGrouppedByOrg[$website->getId()] = $websiteToCompare->getId();
                        break;
                    }
                }
            }
        }

        return $this->websitesGrouppedByOrg;
    }

    /**
     * Collect list of websites for the active orgs
     *
     * @return array
     */
    public function getOrgsWebsites()
    {
        $websitesByOrg = $this->getWebsitesGrouppedByOrg();
        $orgsWebsites = [];

        foreach ($websitesByOrg as $websiteId => $baseWebsite) {
            if (!$this->getSalesforceStatus($websiteId)) {
                continue;
            }
            $orgsWebsites[] = $websiteId;
        }

        return $orgsWebsites;
    }

    /**
     * Collect list of websites for the current org
     *
     * @param int $currentWebsiteId
     * @return int[]
     */
    public function getOrgWebsites($currentWebsiteId)
    {
        $websitesByOrg = $this->getWebsitesGrouppedByOrg();

        $currentOrgWebsites = [];
        foreach ($websitesByOrg as $websiteId => $baseWebsite) {
            if (!$this->getSalesforceStatus($websiteId)) {
                continue;
            }

            if ($websitesByOrg[$currentWebsiteId] === $baseWebsite) {
                $currentOrgWebsites[] = (int)$websiteId;
            }
        }

        return $currentOrgWebsites;
    }

    /**
     * @return array
     * @throws LocalizedException
     * @deprecated
     * @see getOrgWebsites
     */
    public function getCurrentOrgWebsites()
    {
        return $this->getOrgWebsites($this->storeManager->getWebsite()->getId());
    }

    /**
     * Base Website Id Login
     *
     * @param int $websiteId
     *
     * @return mixed
     */
    public function baseWebsiteIdLogin($websiteId)
    {
        $grouped = $this->getWebsitesGrouppedByOrg();
        if (isset($grouped[$websiteId])) {
            return $grouped[$websiteId];
        }

        return $websiteId;
    }

    /**
     * Get Page Size
     *
     * @param int|null $websiteId
     * @return int
     */
    public function getPageSizeFromMagento($websiteId = null)
    {
        return (int)$this->getStoreConfig('tnwsforce_general/synchronization/page_size_from_magento', $websiteId);
    }

    /**
     * Get Log status
     *
     * @param int|null $websiteId
     * @return int
     */
    public function getLogStatus($websiteId = null)
    {
        return (int)$this->getStoreConfig('tnwsforce_general/debug/logstatus', $websiteId);
    }

    /**
     * @return int
     */
    public function logBaseDay()
    {
        $baseDay = $this->scopeConfig->getValue(
            'tnwsforce_general/debug/logbaseday'
        );

        if (!is_int($baseDay) || $baseDay < 1) {
            $baseDay = self::BASE_DAY;
        }

        return $baseDay;
    }

    /**
     * Get DB Log status
     *
     * @param int|null $websiteId
     * @return int
     */
    public function getDbLogStatus($websiteId = null)
    {
        return (int)$this->getStoreConfig('tnwsforce_general/debug/dblogstatus', $websiteId);
    }

    /**
     * Get Log status
     *
     * @param int|null $websiteId
     *
     * @return int
     */
    public function getLogDebug($websiteId = null)
    {
        return (int)$this->getStoreConfig('tnwsforce_general/debug/logdebug', $websiteId);
    }

    /**
     * @param int|null $websiteId
     *
     * @return string
     */
    public function getDbLogLimit($websiteId = null)
    {
        return $this->getStoreConfig('tnwsforce_general/debug/db_log_limit', $websiteId);
    }

    /**
     * Get Clear System Logs
     *
     * @param int|null $websiteId
     * @return int
     */
    public function getClearSystemLogs($websiteId = null)
    {
        return (int)$this->getStoreConfig('tnwsforce_general/debug/clearsystemlogs', $websiteId);
    }

    /**
     * Get Clear System Logs
     *
     * @param int|null $websiteId
     * @return int
     */
    public function getDebugLogClearDays($websiteId = null)
    {
        return (int)$this->getStoreConfig('tnwsforce_general/debug/debugcleardays', $websiteId);
    }

    /**
     * Get log path
     *
     * @return string
     * @throws FileSystemException
     */
    public function getLogDir()
    {
        return $this->directoryList->getPath(DirectoryList::LOG)
            . DIRECTORY_SEPARATOR . 'sforce.log';
    }

    /**
     * @param $path
     * @param int|null $websiteId
     *
     * @return mixed|null|string
     */
    public function getStoreConfig($path, $websiteId = null)
    {
        $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        $scopeCode = null;

        try {
            $websiteId = $this->websiteDetector->detectCurrentWebsite($websiteId);
        } catch (LocalizedException $e) {
            $websiteId = null;
        }

        if ($websiteId !== null) {
            $scopeType = ScopeInterface::SCOPE_WEBSITE;
            $scopeCode = $websiteId;
        }

        return $this->scopeConfig->getValue($path, $scopeType, $scopeCode);
    }

    /**
     * @return array|bool
     */
    public function isSalesForceIntegrationActive()
    {
        if ($this->isIntegrationActive === null) {
            $this->isIntegrationActive = false;

            foreach ($this->storeManager->getWebsites() as $website) {
                if ($this->getSalesforceStatus($website->getId())) {
                    $this->isIntegrationActive = true;
                }
            }
        }

        return $this->isIntegrationActive;
    }

    /**
     * Get cron maximum attempt count to sync
     * @return int
     */
    public function getSyncMaxAttemptsCount()
    {
        $value = (int)$this->getStoreConfig(self::SYNC_MAX_ATTEMPT_COUNT_XML);
        if (!$value) {
            $value = 5;
        }

        return $value;
    }

    /**
     * Get cron maximum attempt count to take response given the flag for additional attempts
     * @param bool $additionalAttempts
     * @return int
     */
    public function getMaxAdditionalAttemptsCount($additionalAttempts = false)
    {
        return !$additionalAttempts ? $this->getSyncMaxAttemptsCount() : $this->getSyncMaxAttemptsCount() * 2;
    }

    /**
     * Get Page Size
     *
     * @param int|null $websiteId
     * @return int
     */
    public function getMQMode($websiteId = null)
    {
        return $this->getStoreConfig('tnwsforce_general/synchronization/mq_mode', $websiteId);
    }
}
