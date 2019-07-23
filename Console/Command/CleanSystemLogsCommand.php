<?php

namespace TNW\Salesforce\Console\Command;

use TNW\Salesforce\Cron\ClearSystemLog;
use Symfony\Component\Console\Command\Command;
use Magento\Framework\Console\Cli;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class CleanSystemLogsCommand
 *
 * @package TNW\Salesforce\Console
 */
class CleanSystemLogsCommand extends Command
{

    /** @var ClearSystemLog */
    protected $clearSystemLogCron;

    /**
     * @var \TNW\Salesforce\Model\Config
     */
    private $salesforceConfig;

    /**
    * @var \Psr\Log\LoggerInterface\Log
    */
    private $_logger;

    /**
     * @var TimezoneInterface
     */
    private $timezone;

    /**
     * CleanSystemLogsCommand constructor.
     */
    public function __construct(
        ClearSystemLog $clearSystemLogCron,
        \TNW\Salesforce\Model\Config $salesforceConfig,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Filesystem\Driver\File $file,
        TimezoneInterface $timezone
    )
    {
        $this->_logger = $logger;
        $this->file = $file;
        $this->timezone = $timezone;
        $this->salesforceConfig = $salesforceConfig;
        $this->clearSystemLogCron = $clearSystemLogCron;
        parent::__construct();
    }

    public function configure()
    {

        $this->setName('tnw_salesforce:clean_system_logs')
            ->setDescription('Clear the old system logs files.');

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {

        try {

            if (!$this->salesforceConfig->getClearSystemLogs()) {
                $output->writeln($this->getDateTime() . ': ' .' Clear System logs not configured.');
                $this->_logger->info($this->getDateTime() . ': ' .' Clear System logs not configured');
                return;
            }

            $executeClearDebuglog = $this->clearSystemLogCron->execute();
            
            if($executeClearDebuglog){
                $output->writeln($this->getDateTime() . ': ' .'Cleared logs successfully.');
            }
            
           
        } catch (\Exception $e) {
            $output->writeln($this->getDateTime() . ': ' . $e->getMessage());
        }

        return Cli::RETURN_SUCCESS;
    }


    /**
     * @return string
     */
    public function getDateTime()
    {
        return $this->timezone->date()->format('m/d/y H:i:s');
    }

}