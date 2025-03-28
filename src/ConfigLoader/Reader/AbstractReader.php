<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\ConfigLoader\Reader;

use Butschster\ContextGenerator\ConfigLoader\Exception\ReaderException;
use Butschster\ContextGenerator\FilesInterface;
use Psr\Log\LoggerInterface;

/**
 * Base class for configuration readers
 */
abstract readonly class AbstractReader implements ReaderInterface
{
    public function __construct(
        protected FilesInterface $files,
        protected ?LoggerInterface $logger = null,
    ) {}

    public function read(string $path): array
    {
        $this->logger?->debug('Reading config file', [
            'path' => $path,
            'reader' => static::class,
        ]);

        $content = $this->files->read($path);

        if ($content === false) {
            $errorMessage = \sprintf('Unable to read configuration file: %s', $path);
            $this->logger?->error($errorMessage);
            throw new ReaderException($errorMessage);
        }

        $this->logger?->debug('Parsing content', [
            'contentLength' => \strlen($content),
            'reader' => static::class,
        ]);

        try {
            $config = $this->parseContent($content);
            $this->logger?->debug('Content successfully parsed', [
                'reader' => static::class,
            ]);

            return $config;
        } catch (\Throwable $e) {
            $errorMessage = \sprintf('Failed to parse configuration file: %s', $path);
            $this->logger?->error($errorMessage, [
                'error' => $e->getMessage(),
                'reader' => static::class,
            ]);
            throw new ReaderException($errorMessage, previous: $e);
        }
    }

    public function supports(string $path): bool
    {
        if (!$this->files->exists($path) || !\is_file($path)) {
            return false;
        }

        $extension = \pathinfo($path, PATHINFO_EXTENSION);
        $isSupported = \in_array($extension, $this->getSupportedExtensions(), true);

        $this->logger?->debug('Checking if config file is supported', [
            'path' => $path,
            'extension' => $extension,
            'isSupported' => $isSupported,
            'reader' => static::class,
        ]);

        return $isSupported;
    }

    /**
     * Parse the raw content into a configuration array
     *
     * @param string $content Raw configuration content
     * @return array<mixed> Parsed configuration data
     * @throws \Throwable If parsing fails
     */
    abstract protected function parseContent(string $content): array;

    abstract protected function getSupportedExtensions(): array;
}
