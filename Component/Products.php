<?php
namespace CtiDigital\Configurator\Component;

use CtiDigital\Configurator\Api\ComponentInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class Products implements ComponentInterface
{
    protected $alias = 'products';
    protected $description = 'Component to import products using a CSV file.';

    /**
     * @param null $data
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function execute($data = null)
    {
        $this->log->logError('Not supported without FSI');
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
