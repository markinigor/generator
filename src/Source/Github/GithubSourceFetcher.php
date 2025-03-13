<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\Github;

use Butschster\ContextGenerator\Fetcher\SourceFetcherInterface;
use Butschster\ContextGenerator\Lib\Finder\FinderInterface;
use Butschster\ContextGenerator\Modifier\SourceModifierRegistry;
use Butschster\ContextGenerator\SourceInterface;

/**
 * Fetcher for GitHub repository sources
 *
 * @implements SourceFetcherInterface<GithubSource>
 */
final readonly class GithubSourceFetcher implements SourceFetcherInterface
{
    public function __construct(
        private FinderInterface $finder,
        private SourceModifierRegistry $modifiers,
    ) {}

    public function supports(SourceInterface $source): bool
    {
        return $source instanceof GithubSource;
    }

    public function fetch(SourceInterface $source): string
    {
        if (!$source instanceof GithubSource) {
            throw new \InvalidArgumentException('Source must be an instance of GithubSource');
        }

        $content = '';

        // Find files using the finder and get the FinderResult
        $finderResult = $this->finder->find($source);

        // Add tree view if requested
        if ($source->showTreeView) {
            $content .= "```\n";
            $content .= $finderResult->treeView;
            $content .= "```\n\n";
        }

        // Fetch and add the content of each file
        foreach ($finderResult->files as $file) {
            $fileContent = $file->getContents();
            $path = $file->getRelativePath();
            // Apply modifiers if available
            if (!empty($source->modifiers)) {
                foreach ($source->modifiers as $modifierId) {
                    if ($this->modifiers->has($modifierId)) {
                        $modifier = $this->modifiers->get($modifierId);
                        if ($modifier->supports($path)) {
                            $fileContent = $modifier->modify($fileContent, $modifierId->context);
                        }
                    }
                }
            }

            $content .= "```\n";
            $content .= "// Path: {$path}\n";
            $content .= \trim((string) $fileContent) . "\n\n";
            $content .= "```\n";
        }

        return $content;
    }
}
