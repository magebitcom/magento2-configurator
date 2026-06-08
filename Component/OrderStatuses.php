<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

namespace Magebit\Configurator\Component;

use Magebit\Configurator\Api\ComponentInterface;
use Magebit\Configurator\Api\LoggerInterface;
use Magebit\Configurator\Exception\ComponentException;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magento\Sales\Model\Order\Status;
use Magento\Sales\Model\Order\StatusFactory;
use Magento\Sales\Model\ResourceModel\Order\Status as StatusResource;
use Magento\Sales\Model\ResourceModel\Order\StatusFactory as StatusResourceFactory;

/**
 * Class OrderStatuses
 * @package Magebit\Configurator\Component
 */
class OrderStatuses implements ComponentInterface
{
    protected $alias = 'order_statuses';
    protected $name = 'Order Statuses';
    protected $description = 'Component to create custom order statuses';

    /**
     * @var StatusFactory
     */
    protected $statusFactory;

    /**
     * @var StatusResourceFactory
     */
    protected $statusResourceFactory;

    /**
     * @var LoggerInterface
     */
    private $log;

    /**
     * OrderStatuses constructor.
     * @param StatusFactory $statusFactory
     * @param StatusResourceFactory $statusResourceFactory
     * @param LoggerInterface $log
     */
    public function __construct(
        StatusFactory $statusFactory,
        StatusResourceFactory $statusResourceFactory,
        LoggerInterface $log
    ) {
        $this->statusFactory = $statusFactory;
        $this->statusResourceFactory = $statusResourceFactory;
        $this->log = $log;
    }

    /**
     * @param $data
     */
    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        $data = $context->getData();

        if (isset($data['order_statuses'])) {
            foreach ($data['order_statuses'] as $statusSet) {
                try {
                    $this->createOrderStatuses($statusSet);
                } catch (ComponentException $e) {
                    $this->log->logError($e->getMessage());
                    $result->addError($e->getMessage());
                }
            }
        }

        return $result;
    }

    /**
     * @param $statusSet
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     */
    public function createOrderStatuses($statusSet)
    {
        foreach ($statusSet['statuses'] as $statusData) {
            /** @var StatusResource $statusResource */
            $statusResource = $this->statusResourceFactory->create();
            /** @var Status $status */
            $status = $this->statusFactory->create();
            $status->setData([
                'status' => $statusData['code'],
                'label' => $statusData['name'],
            ]);

            try {
                $statusResource->save($status);
            } catch (ComponentException $e) {
                $this->log->logError($e->getMessage());
            }

            $status->assignState($statusSet['state'], false, true);

            $this->log->logInfo(
                sprintf('Order status %s created', $statusData['name'])
            );
        }
    }

    /**
     * @return string
     */
    public function getAlias()
    {
        return $this->alias;
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }
}
