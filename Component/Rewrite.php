<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

namespace Magebit\Configurator\Component;

class Rewrite
{
    private $requestPath;
    private $targetPath;
    private $redirectType;
    private $storeId;
    private $description;

    /**
     * ExpectedRewrite constructor.
     *
     * @param string $requestPath
     * @param string $targetPath
     * @param string $redirectType
     * @param string $storeId
     * @param string $description
     */
    public function __construct($requestPath, $targetPath, $redirectType, $storeId, $description)
    {
        $this->requestPath = $requestPath;
        $this->targetPath = $targetPath;
        $this->redirectType = $redirectType;
        $this->storeId = $storeId;
        $this->description = $description;
    }

    /**
     * @return string
     */
    public function getRequestPath()
    {
        return $this->requestPath;
    }

    /**
     * @param string $requestPath
     */
    public function setRequestPath($requestPath)
    {
        $this->requestPath = $requestPath;
    }

    /**
     * @return string
     */
    public function getTargetPath()
    {
        return $this->targetPath;
    }

    /**
     * @param string $targetPath
     */
    public function setTargetPath($targetPath)
    {
        $this->targetPath = $targetPath;
    }

    /**
     * @return string
     */
    public function getRedirectType()
    {
        return $this->redirectType;
    }

    /**
     * @param string $redirectType
     */
    public function setRedirectType($redirectType)
    {
        $this->redirectType = $redirectType;
    }

    /**
     * @return string
     */
    public function getStoreId()
    {
        return $this->storeId;
    }

    /**
     * @param string $storeId
     */
    public function setStoreId($storeId)
    {
        $this->storeId = $storeId;
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * @param string $description
     */
    public function setDescription($description)
    {
        $this->description = $description;
    }
}
