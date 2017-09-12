<?php

/**
 * Pimcore Customer Management Framework Bundle
 * Full copyright and license information is available in
 * License.md which is distributed with this source code.
 *
 * @copyright  Copyright (C) Elements.at New Media Solutions GmbH
 * @license    GPLv3
 */

namespace CustomerManagementFrameworkBundle\CustomerSaveManager;

use CustomerManagementFrameworkBundle\CustomerProvider\CustomerProviderInterface;
use CustomerManagementFrameworkBundle\CustomerSaveHandler\CustomerSaveHandlerInterface;
use CustomerManagementFrameworkBundle\CustomerSaveValidator\CustomerSaveValidatorInterface;
use CustomerManagementFrameworkBundle\Model\CustomerInterface;
use CustomerManagementFrameworkBundle\Newsletter\Queue\NewsletterQueueInterface;
use CustomerManagementFrameworkBundle\Traits\LoggerAware;
use Pimcore\Db;
use Pimcore\Model\Version;

class DefaultCustomerSaveManager implements CustomerSaveManagerInterface
{
    use LoggerAware;
    use LegacyTrait;

    /**
     * @var SaveOptions
     */
    private $saveOptions;

    /**
     * @var SaveOptions
     */
    private $defaultSaveOptions;

    /**
     * @var CustomerSaveHandlerInterface[]
     */
    protected $saveHandlers = [];

    /**
     * @var CustomerProviderInterface
     */
    private $customerProvider;

    /**
     * DefaultCustomerSaveManager constructor.
     * @param bool $enableAutomaticObjectNamingScheme
     */
    public function __construct(SaveOptions $saveOptions, CustomerProviderInterface $customerProvider)
    {
        $this->saveOptions = $saveOptions;
        $this->defaultSaveOptions = clone($saveOptions);
        $this->customerProvider = $customerProvider;
    }

    protected function applyNamingScheme(CustomerInterface $customer)
    {
        if ($this->saveOptions->isObjectNamingSchemeEnabled()) {
            $this->customerProvider->applyObjectNamingScheme($customer);
        }
    }

    public function preAdd(CustomerInterface $customer)
    {
        if ($customer->getPublished()) {
            $this->validateOnSave($customer);
        }
        if ($this->saveOptions->isSaveHandlersExecutionEnabled()) {
            $this->applySaveHandlers($customer, 'preAdd', true);
        }

        $this->applyNamingScheme($customer);
    }

    public function postAdd(CustomerInterface $customer)
    {
        $this->handleNewsletterQueue($customer, NewsletterQueueInterface::OPERATION_UPDATE);

        if ($this->saveOptions->isOnSaveSegmentBuildersEnabled()) {
            \Pimcore::getContainer()->get('cmf.segment_manager')->buildCalculatedSegmentsOnCustomerSave($customer);
        }

        if ($this->saveOptions->isSegmentBuilderQueueEnabled()) {
            \Pimcore::getContainer()->get('cmf.segment_manager')->addCustomerToChangesQueue($customer);
        }

        if ($this->saveOptions->isDuplicatesIndexEnabled()) {
            \Pimcore::getContainer()->get('cmf.customer_duplicates_service')->updateDuplicateIndexForCustomer(
                $customer
            );
        }
    }

    public function preUpdate(CustomerInterface $customer)
    {
        if (!$customer->getIdEncoded()) {
            $customer->setIdEncoded(md5($customer->getId()));
        }

        if ($this->saveOptions->isSaveHandlersExecutionEnabled()) {
            $this->applySaveHandlers($customer, 'preUpdate', true);
        }
        $this->validateOnSave($customer, true);
        $this->applyNamingScheme($customer);
    }

    public function postUpdate(CustomerInterface $customer)
    {

        $this->handleNewsletterQueue($customer, NewsletterQueueInterface::OPERATION_UPDATE);

        if ($this->saveOptions->isSaveHandlersExecutionEnabled()) {
            $this->applySaveHandlers($customer, 'postUpdate');
        }

        if ($this->saveOptions->isOnSaveSegmentBuildersEnabled()) {
            \Pimcore::getContainer()->get('cmf.segment_manager')->buildCalculatedSegmentsOnCustomerSave($customer);
        }

        if ($this->saveOptions->isSegmentBuilderQueueEnabled()) {
            \Pimcore::getContainer()->get('cmf.segment_manager')->addCustomerToChangesQueue($customer);
        }

        if ($this->saveOptions->isDuplicatesIndexEnabled()) {
            \Pimcore::getContainer()->get('cmf.customer_duplicates_service')->updateDuplicateIndexForCustomer(
                $customer
            );
        }
    }

    public function preDelete(CustomerInterface $customer)
    {
        if (!$this->saveOptions->isSaveHandlersExecutionEnabled()) {
            $this->applySaveHandlers($customer, 'preDelete', true);
        }
    }

    public function postDelete(CustomerInterface $customer)
    {
        if (!$this->saveOptions->isSaveHandlersExecutionEnabled()) {
            $this->applySaveHandlers($customer, 'postDelete');
        }

        $this->addToDeletionsTable($customer);

        $this->handleNewsletterQueue($customer, NewsletterQueueInterface::OPERATION_DELETE);
    }

    public function validateOnSave(CustomerInterface $customer, $withDuplicatesCheck = true)
    {
        if (!$this->saveOptions->isValidatorEnabled()) {
            return false;
        }

        /**
         * @var CustomerSaveValidatorInterface $validator
         */
        $validator = \Pimcore::getContainer()->get('cmf.customer_save_validator');

        return $validator->validate($customer, $withDuplicatesCheck);
    }

    protected function handleNewsletterQueue(CustomerInterface $customer, $operation)
    {
        if($this->saveOptions->isNewsletterQueueEnabled()) {
            /**
             * @var NewsletterQueueInterface $newsletterQueue
             */
            $newsletterQueue = \Pimcore::getContainer()->get('cmf.newsletter.queue');
            $newsletterQueue->enqueueCustomer($customer, $operation, null, $this->saveOptions->isNewsletterQueueImmidiateAsyncExecutionEnabled());
        }

    }

    protected function addToDeletionsTable(CustomerInterface $customer)
    {
        $db = Db::get();
        $db->insertOrUpdate(
            'plugin_cmf_deletions',
            [
                'id' => $customer->getId(),
                'creationDate' => time(),
                'entityType' => 'customers',
            ]
        );
    }

    protected function applySaveHandlers(CustomerInterface $customer, $saveHandlerMethod, $reinitSaveHandlers = false)
    {
        $saveHandlers = $this->getSaveHandlers();

        if ($reinitSaveHandlers) {
            $this->reinitSaveHandlers($saveHandlers, $customer);
        }

        foreach ($saveHandlers as $handler) {
            $this->getLogger()->debug(
                sprintf(
                    'apply save handler %s %s method to customer %s',
                    get_class($handler),
                    $saveHandlerMethod,
                    (string)$customer
                )
            );

            if ($saveHandlerMethod == 'preAdd') {
                $handler->preAdd($customer);
                $handler->preSave($customer);
            } elseif ($saveHandlerMethod == 'preUpdate') {
                $handler->preUpdate($customer);
                $handler->preSave($customer);
            } elseif ($saveHandlerMethod == 'postUpdate') {
                $handler->postUpdate($customer);
                $handler->postSave($customer);
            } elseif ($saveHandlerMethod == 'postAdd') {
                $handler->postAdd($customer);
                $handler->postSave($customer);
            } elseif ($saveHandlerMethod == 'preDelete') {
                $handler->preDelete($customer);
            } elseif ($saveHandlerMethod == 'postDelete') {
                $handler->postDelete($customer);
            }
        }
    }

    /**
     * @param CustomerSaveHandlerInterface[] $saveHandlers
     * @param CustomerInterface $customer
     */
    protected function reinitSaveHandlers(array $saveHandlers, CustomerInterface $customer)
    {
        $originalCustomer = null;
        foreach ($saveHandlers as $handler) {
            if (!$handler->isOriginalCustomerNeeded()) {
                continue;
            }

            if(is_null($originalCustomer)) {
                $originalCustomer = $this->customerProvider->getById($customer->getId(), true);
            }

            $handler->setOriginalCustomer($originalCustomer);
        }
    }


    /**
     * @param CustomerInterface $customer
     *
     * @return mixed
     */
    public function saveDirty(CustomerInterface $customer, $disableVersions = true)
    {
        return $this->saveWithOptions($customer, $this->createDirtyOptions(), $disableVersions);
    }

    /**
     * @return CustomerSaveHandlerInterface[]
     */
    public function getSaveHandlers()
    {
        return $this->saveHandlers;
    }

    /**
     * @param CustomerSaveHandlerInterface[] $saveHandlers
     */
    public function setSaveHandlers(array $saveHandlers)
    {
        $this->saveHandlers = $saveHandlers;
    }

    public function addSaveHandler(CustomerSaveHandlerInterface $saveHandler)
    {
        $this->saveHandlers[] = $saveHandler;
    }

    /**
     * Disable all
     *
     * @return SaveOptions
     */
    protected function createDirtyOptions()
    {
        return new SaveOptions();
    }

    /**
     * @param CustomerInterface $customer
     * @param SaveOptions $options
     * @param bool $disableVersions
     * @return mixed
     */
    public function saveWithOptions(CustomerInterface $customer, SaveOptions $options, $disableVersions = false)
    {
        // retrieve default options
        $backupOptions = $this->getSaveOptions();
        // apply desired options
        $this->applySaveOptions($options);

        // backup current version option
        $versionsEnabled = !Version::$disabled;
        if ($disableVersions) {
            Version::disable();
        }

        try {
            return $customer->save();
        } finally {
            // restore version options
            if ($disableVersions && $versionsEnabled) {
                Version::enable();
            }

            // restore default options
            $this->applySaveOptions($backupOptions);
        }
    }

    /**
     * @param bool $clone
     *
     * @return SaveOptions
     */
    public function getSaveOptions($clone = false)
    {
        if($clone) {
            return clone($this->saveOptions);
        }
        return $this->saveOptions;
    }

    public function setSaveOptions(SaveOptions $saveOptions)
    {
        $this->saveOptions = $saveOptions;
    }

    public function getDefaultSaveOptions()
    {
        return clone($this->defaultSaveOptions);
    }

    /**
     * Restore options
     *
     * @param \stdClass $options
     */
    protected function applySaveOptions(SaveOptions $options)
    {
        $this->saveOptions = $options;
    }
}
