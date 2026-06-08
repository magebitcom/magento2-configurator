<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Model;

use Magebit\Configurator\Api\ComponentMode;
use Closure;

/**
 * Everything a component needs to process a single source: the parsed data
 * (or the raw path, for file-based components), the run mode, the environment,
 * and the dry-run flag. Built per source by the Processor.
 *
 * Source data is parsed lazily on first getData() call so file-based components
 * that only want the path never pay for a parse.
 */
class ComponentContext
{
    /** @var Closure(string):array */
    private Closure $parser;

    /** @var array|null */
    private ?array $parsedData = null;

    /**
     * @param Closure(string):array $parser
     */
    public function __construct(
        private readonly string $sourcePath,
        private readonly ComponentMode $mode,
        private readonly string $environment,
        private readonly bool $dryRun,
        Closure $parser
    ) {
        $this->parser = $parser;
    }

    /**
     * Raw source path, for components that read the file themselves.
     */
    public function getSourcePath(): string
    {
        return $this->sourcePath;
    }

    /**
     * Parsed source data (memoised).
     */
    public function getData(): array
    {
        if ($this->parsedData === null) {
            $this->parsedData = ($this->parser)($this->sourcePath);
        }

        return $this->parsedData;
    }

    public function getMode(): ComponentMode
    {
        return $this->mode;
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }
}
