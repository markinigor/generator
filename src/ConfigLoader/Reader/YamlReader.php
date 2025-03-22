<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\ConfigLoader\Reader;

use Butschster\ContextGenerator\ConfigLoader\Exception\ReaderException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reader for YAML configuration files
 */
final readonly class YamlReader extends AbstractReader
{
    protected function parseContent(string $content): array
    {
        try {
            if (!\class_exists(Yaml::class)) {
                throw new ReaderException(
                    'Symfony Yaml component is required to parse YAML files. Please install symfony/yaml package.',
                );
            }

            $config = Yaml::parse($content);

            if (!\is_array($config)) {
                throw new ReaderException('YAML configuration must parse to an array');
            }

            return $config;
        } catch (ParseException $e) {
            throw new ReaderException('Invalid YAML in configuration file', previous: $e);
        }
    }

    protected function getSupportedExtensions(): array
    {
        return ['yaml', 'yml'];
    }
}
