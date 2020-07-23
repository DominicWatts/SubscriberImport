<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Xigen\SubscriberImport\Console\Command;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Model\CustomerFactory;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Framework\File\Csv;
use Magento\Framework\Filesystem;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Newsletter\Model\ResourceModel\Subscriber;
use Magento\Newsletter\Model\ResourceModel\Subscriber\CollectionFactory;
use Magento\Newsletter\Model\Subscriber as ModelSubscriber;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\ProgressBarFactory;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Zend\Validator\EmailAddress;

/**
 * @param \Magento\Framework\App\Helper\Context $context
 */
class ImportSubscribers extends Command
{
    const IMPORT_ARGUMENT = 'import';
    const FILE_PATH = 'xigen/subscriber-export.csv';
    const JAS_WEBSITE_ID = 1;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Symfony\Component\Console\Input\InputInterface
     */
    protected $input;

    /**
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    protected $output;

    /**
     * @var \Magento\Framework\App\State
     */
    protected $state;

    /**
     * @var \Magento\Framework\File\Csv
     */
    protected $csv;

    /**
     * @var string
     */
    protected $mediaPath;

    /**
     * @var string
     */
    protected $fullPath;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    protected $dateTime;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $_objectManager;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var CustomerRepositoryInterface
     */
    protected $customerRepositoryInterface;

    /**
     * @var CustomerFactory
     */
    protected $customerFactory;

    /**
     * @var SubscriberFactory
     */
    protected $subscriberFactory;

    /**
     * @var CollectionFactory
     */
    protected $collectionFactory;

    /**
     * @var DataObjectHelper
     */
    private $dataObjectHelper;

    /**
     * @var \Magento\Customer\Api\Data\CustomerInterfaceFactory
     */
    protected $customerInterfaceFactory;

    /**
     * Undocumented function
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Framework\App\State $state
     * @param \Magento\Framework\File\Csv $csv
     * @param \Magento\Framework\Filesystem $filesystem
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $dateTime
     * @param \Symfony\Component\Console\Helper\ProgressBarFactory $progressBarFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Customer\Api\CustomerRepositoryInterface $customerRepositoryInterface
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        LoggerInterface $logger,
        State $state,
        Csv $csv,
        Filesystem $filesystem,
        DateTime $dateTime,
        ProgressBarFactory $progressBarFactory,
        StoreManagerInterface $storeManager,
        CustomerRepositoryInterface $customerRepositoryInterface,
        ScopeConfigInterface $scopeConfig,
        CustomerFactory $customerFactory,
        SubscriberFactory $subscriberFactory,
        CollectionFactory $collectionFactory,
        DataObjectHelper $dataObjectHelper,
        CustomerInterfaceFactory $customerInterfaceFactory
    ) {
        $this->logger = $logger;
        $this->state = $state;
        $this->csv = $csv;
        $this->filesystem = $filesystem;
        $this->dateTime = $dateTime;
        $this->progressBarFactory = $progressBarFactory;
        $this->storeManager = $storeManager;
        $this->_scopeConfig = $scopeConfig;
        $this->customerRepositoryInterface = $customerRepositoryInterface;
        $this->customerFactory = $customerFactory;
        $this->subscriberFactory = $subscriberFactory;
        $this->collectionFactory = $collectionFactory;
        $this->dataObjectHelper = $dataObjectHelper;
        $this->customerInterfaceFactory = $customerInterfaceFactory;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName("xigen:import-subscriber")
            ->setDescription("Update Subscriber Data")
            ->setDefinition([
                new InputArgument(self::IMPORT_ARGUMENT, InputArgument::REQUIRED, 'Import'),
            ]);
        parent::configure();
    }

    /**
     * {@inheritdoc}
     * xigen:import-subscriber <import>
     * php bin/magento xigen:import-subscriber import
     * Notes:
     *   - Customer data needs to be in place first
     *   - Reads from /pub/media/xigen/
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->storeManager->setCurrentStore(1);
        $this->state->setAreaCode(Area::AREA_GLOBAL);
        $this->mediaPath = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath();
        $this->fullPath = $this->mediaPath . self::FILE_PATH;

        $this->output->writeln((string) __(
            "[%1] Reading from %2",
            $this->dateTime->gmtDate(),
            $this->fullPath
        ));

        $importRows = $this->csv->getData($this->fullPath);
        unset($importRows[0]);

        $import = $input->getArgument(self::IMPORT_ARGUMENT) ?: false;

        if ($import) {

            /** @var ProgressBar $progress */
            $progress = $this->progressBarFactory->create(
                [
                    'output' => $this->output,
                    'max' => count($importRows)
                ]
            );

            $progress->setFormat(
                "%current%/%max% [%bar%] %percent:3s%% %elapsed% %memory:6s% \t| <info>%message%</info>"
            );

            if ($output->getVerbosity() !== OutputInterface::VERBOSITY_NORMAL) {
                $progress->setOverwrite(false);
            }

            $this->output->writeln((string) __(
                "[%1] Start",
                $this->dateTime->gmtDate()
            ));

            $validator = new EmailAddress();

            foreach ($importRows as $row) {
                $progress->advance();

                $email = trim($row[0]);
                if (!$validator->isValid($email)) {
                    continue;
                }

                if ((int) $row[1] == ModelSubscriber::STATUS_UNSUBSCRIBED) {
                    continue;
                }

                if ((int) $row[2] != self::JAS_WEBSITE_ID) {
                    continue;
                }

                $progress->setMessage((string) __(
                    'Email: %1 Status: %2 Website: %3',
                    $email,
                    $row[1],
                    $row[2]
                ));

                $result = $this->subscribe($email);
            }

            $progress->finish();
            $this->output->writeln('');

            $this->output->writeln((string) __(
                "[%1] Finish",
                $this->dateTime->gmtDate()
            ));

            if ($this->input->isInteractive()) {
                return Cli::RETURN_SUCCESS;
            }
        }

        if ($this->input->isInteractive()) {
            return Cli::RETURN_FAILURE;
        }
    }

    /**
     * Subscribes by email
     * @param string $email
     * @return bool
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function subscribe($email)
    {
        $subscriber = $this->loadByEmail($email);

        if ($subscriber->getId() && $subscriber->getStatus() == ModelSubscriber::STATUS_SUBSCRIBED) {
            return false;
        }

        if (!$subscriber->getId()) {
            $subscriber->setSubscriberConfirmCode($subscriber->randomSequence());
        }

        if (!$subscriber->getId() ||
             $subscriber->getStatus() == ModelSubscriber::STATUS_UNSUBSCRIBED ||
             $subscriber->getStatus() == ModelSubscriber::STATUS_NOT_ACTIVE
        ) {
            $subscriber->setStatus(ModelSubscriber::STATUS_SUBSCRIBED);
            $subscriber->setSubscriberEmail($email);
        }

        try {
            $customer = $this->customerRepositoryInterface->get($email);
            $subscriber->setStoreId($customer->getStoreId());
            $subscriber->setCustomerId($customer->getId());
        } catch (\Exception $e) {
            $subscriber->setStoreId($this->storeManager->getStore()->getId());
            $subscriber->setCustomerId(0);
        }

        $subscriber->setStatusChanged(true);

        try {
            $subscriber->save();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Load subscriber data from resource model by email
     * @param string $subscriberEmail
     * @return $this
     */
    public function loadByEmail($subscriberEmail)
    {
        $storeId = $this->storeManager->getStore()->getId();
        $customerData = [
            'store_id' => $storeId,
            'email'=> $subscriberEmail
        ];

        /** @var \Magento\Customer\Api\Data\CustomerInterface $customer */
        $customer = $this->customerInterfaceFactory->create();
        $this->dataObjectHelper->populateWithArray(
            $customer,
            $customerData,
            CustomerInterface::class
        );

        $subscriber = $this->subscriberFactory->create();

        $array = $subscriber->getResource()
            ->loadByCustomerData($customer);

        return $subscriber->addData($array);
    }
}
