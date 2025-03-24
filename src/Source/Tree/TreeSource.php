<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\Tree;

use Butschster\ContextGenerator\Fetcher\FilterableSourceInterface;
use Butschster\ContextGenerator\Lib\TreeBuilder\TreeViewConfig;
use Butschster\ContextGenerator\Source\BaseSource;

/**
 * Tree source for generating hierarchical visualizations of directory structures
 */
final class TreeSource extends BaseSource implements FilterableSourceInterface
{
    /**
     * @param string|array<string> $sourcePath Path(s) to generate tree from
     * @param string $description Human-readable description
     * @param string|array<string> $filePattern Pattern(s) to match files
     * @param array<string> $notPath Patterns to exclude paths
     * @param string|array<string> $path Patterns to include only specific paths
     * @param string|array<string> $contains Patterns to include files containing specific content
     * @param string|array<string> $notContains Patterns to exclude files containing specific content
     * @param string $renderFormat Output format for the tree (ascii, markdown, json)
     * @param TreeViewConfig|bool $treeView Tree view configuration
     * @param array<non-empty-string> $tags
     */
    public function __construct(
        public readonly string|array $sourcePath,
        string $description = '',
        public readonly string|array $filePattern = '*',
        public readonly array $notPath = [],
        public readonly string|array $path = [],
        public readonly string|array $contains = [],
        public readonly string|array $notContains = [],
        public readonly string $renderFormat = 'ascii',
        public readonly TreeViewConfig|bool $treeView = true,
        array $tags = [],
    ) {
        parent::__construct(description: $description, tags: $tags);
    }

    /**
     * Create a TreeSource from an array configuration
     */
    public static function fromArray(array $data, string $rootPath = ''): self
    {
        if (!isset($data['sourcePaths'])) {
            throw new \RuntimeException('Tree source must have a "sourcePath" property');
        }

        $sourcePath = $data['sourcePaths'];
        if (!\is_string($sourcePath) && !\is_array($sourcePath)) {
            throw new \RuntimeException('"sourcePath" must be a string or array in source');
        }

        $sourcePath = \is_string($sourcePath) ? [$sourcePath] : $sourcePath;
        $sourcePath = \array_map(
            static fn(string $sourcePath): string => $rootPath . '/' . \trim($sourcePath, '/'),
            $sourcePath,
        );

        // Validate filePattern if present
        if (isset($data['filePattern'])) {
            if (!\is_string($data['filePattern']) && !\is_array($data['filePattern'])) {
                throw new \RuntimeException('filePattern must be a string or an array of strings');
            }

            // If it's an array, make sure all elements are strings
            if (\is_array($data['filePattern'])) {
                foreach ($data['filePattern'] as $pattern) {
                    if (!\is_string($pattern)) {
                        throw new \RuntimeException('All elements in filePattern must be strings');
                    }
                }
            }
        }

        // Validate renderFormat if present
        if (isset($data['renderFormat'])) {
            if (!\is_string($data['renderFormat'])) {
                throw new \RuntimeException('renderFormat must be a string');
            }

            $validFormats = ['ascii'];
            if (!\in_array($data['renderFormat'], $validFormats, true)) {
                throw new \RuntimeException(
                    \sprintf(
                        'Invalid renderFormat: %s. Allowed formats: %s',
                        $data['renderFormat'],
                        \implode(', ', $validFormats),
                    ),
                );
            }
        }

        // Handle filePattern parameter, allowing both string and array formats
        $filePattern = $data['filePattern'] ?? '*';

        // Convert notPath
        $notPath = $data['notPath'] ?? [];

        // Validate dirContext if present
        if (isset($data['dirContext']) && !\is_array($data['dirContext'])) {
            throw new \RuntimeException('dirContext must be an associative array');
        }

        return new self(
            sourcePath: $sourcePath,
            description: $data['description'] ?? '',
            filePattern: $filePattern,
            notPath: $notPath,
            path: $data['path'] ?? [],
            contains: $data['contains'] ?? [],
            notContains: $data['notContains'] ?? [],
            renderFormat: $data['renderFormat'] ?? 'ascii',
            treeView: new TreeViewConfig(
                showSize: $data['showSize'] ?? false,
                showLastModified: $data['showLastModified'] ?? false,
                showCharCount: $data['showCharCount'] ?? false,
                includeFiles: $data['includeFiles'] ?? true,
                maxDepth: $data['maxDepth'] ?? 0,
                dirContext: $data['dirContext'] ?? [],
            ),
            tags: $data['tags'] ?? [],
        );
    }

    // Implementation of FilterableSourceInterface methods

    public function name(): string|array|null
    {
        return $this->filePattern;
    }

    public function path(): string|array|null
    {
        return $this->path;
    }

    public function notPath(): string|array|null
    {
        return $this->notPath;
    }

    public function contains(): string|array|null
    {
        return $this->contains;
    }

    public function notContains(): string|array|null
    {
        return $this->notContains;
    }

    public function size(): string|array|null
    {
        return null;
    }

    public function date(): string|array|null
    {
        return null;
    }

    public function in(): array|null
    {
        $directories = [];

        foreach ((array) $this->sourcePath as $path) {
            if (\is_dir($path)) {
                $directories[] = $path;
            }
        }

        return empty($directories) ? null : $directories;
    }

    public function files(): array|null
    {
        $files = [];

        foreach ((array) $this->sourcePath as $path) {
            if (\is_file($path)) {
                $files[] = $path;
            }
        }

        return empty($files) ? null : $files;
    }

    public function ignoreUnreadableDirs(): bool
    {
        return true;
    }

    public function jsonSerialize(): array
    {
        $result = [
            'type' => 'tree',
            ...parent::jsonSerialize(),
            'sourcePath' => $this->sourcePath,
            'filePattern' => $this->filePattern,
            'notPath' => $this->notPath,
            'renderFormat' => $this->renderFormat,
            ...$this->treeView->jsonSerialize(),
        ];

        // Add optional properties only if they're non-empty
        if (!empty($this->path)) {
            $result['path'] = $this->path;
        }

        if (!empty($this->contains)) {
            $result['contains'] = $this->contains;
        }

        if (!empty($this->notContains)) {
            $result['notContains'] = $this->notContains;
        }

        if (!empty($this->dirContext)) {
            $result['dirContext'] = $this->dirContext;
        }

        return \array_filter($result);
    }
}
