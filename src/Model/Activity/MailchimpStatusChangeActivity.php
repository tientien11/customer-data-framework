<?php

/**
 * Pimcore Customer Management Framework Bundle
 * Full copyright and license information is available in
 * License.md which is distributed with this source code.
 *
 * @copyright  Copyright (C) Elements.at New Media Solutions GmbH
 * @license    GPLv3
 */

namespace CustomerManagementFrameworkBundle\Model\Activity;

use CustomerManagementFrameworkBundle\Model\AbstractActivity;
use CustomerManagementFrameworkBundle\Model\ActivityStoreEntry\ActivityStoreEntryInterface;
use CustomerManagementFrameworkBundle\Model\CustomerInterface;
use Pimcore\Model\Object\ActivityDefinition;

class MailchimpStatusChangeActivity extends AbstractActivity
{
    protected $customer;

    /**
     * @var string
     */
    protected $status;

    /**
     * @var array
     */
    protected $additionalAttributes = [];

    /**
     * @var int
     */
    protected $activityDate;

    const TYPE = 'Mailchimp status change';

    /**
     * MailchimpStatusChangeActivity constructor.
     * @param CustomerInterface $customer
     * @param string $status
     * @param int $activityDate
     */
    public function __construct(CustomerInterface $customer, $status, array $additionalAttributes = [], $activityDate = null)
    {
        if(is_null($activityDate)) {
            $activityDate = time();
        }

        $this->customer = $customer;
        $this->status = $status;
        $this->additionalAttributes = $additionalAttributes;
        $this->activityDate = $activityDate;
    }

    public function cmfGetType()
    {
        return self::TYPE;
    }

    public function cmfToArray()
    {
        $attributes = $this->additionalAttributes;
        $attributes['status'] = $this->status;

        return $attributes;
    }

    public static function cmfGetOverviewData(ActivityStoreEntryInterface $entry)
    {
        return ['status' => $entry->getAttributes()['status']];
    }

    public function cmfWebserviceUpdateAllowed()
    {
        return false;
    }

    /**
     * @param array $data
     * @param bool $fromWebservice
     *
     * @return bool
     */
    public static function cmfCreate(array $data, $fromWebservice = false)
    {

    }
}
