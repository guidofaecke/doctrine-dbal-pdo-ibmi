<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withSets([
        LevelSetList::UP_TO_PHP_83,
    ])
    ->withPhpSets(php83: true)
    ->withAttributesSets(all: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withTypeCoverageLevel(49);
