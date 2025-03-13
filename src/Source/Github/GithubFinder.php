<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\Github;

use Butschster\ContextGenerator\Fetcher\FilterableSourceInterface;
use Butschster\ContextGenerator\Lib\Finder\FinderInterface;
use Butschster\ContextGenerator\Lib\Finder\FinderResult;
use Butschster\ContextGenerator\Lib\PathFilter\ContentsFilter;
use Butschster\ContextGenerator\Lib\PathFilter\ExcludePathFilter;
use Butschster\ContextGenerator\Lib\PathFilter\FilePatternFilter;
use Butschster\ContextGenerator\Lib\PathFilter\FilterInterface;
use Butschster\ContextGenerator\Lib\PathFilter\PathFilter;
use Butschster\ContextGenerator\Lib\TreeBuilder\FileTreeBuilder;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;

/**
 * GitHub content finder implementation
 *
 * Fetches and filters content from GitHub repositories
 */
final class GithubFinder implements FinderInterface
{
    /**
     * GitHub API base URL
     */
    private const API_BASE_URL = 'https://api.github.com';

    /**
     * Repository branch or tag
     */
    private string $branch = 'main';

    /**
     * Filters to apply
     *
     * @var array<FilterInterface>
     */
    private array $filters = [];

    /**
     * Create a new GitHub finder
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private ?string $githubToken = null,
    ) {}

    /**
     * Find files in a GitHub repository based on source configuration
     */
    public function find(FilterableSourceInterface $source, string $basePath = ''): FinderResult
    {
        if (!$source instanceof GithubSource) {
            throw new \InvalidArgumentException('Source must be an instance of GithubSource');
        }

        if ($source->githubToken) {
            $this->setToken($source->githubToken);
        }
        $this->setBranch($source->branch);

        // Parse repository owner and name
        [$owner, $repo] = $this->parseRepository($source->repository);

        // Initialize path filters based on source configuration
        $this->initializePathFilters($source);

        // Get source paths
        $sourcePaths = $source->sourcePaths ?? [];
        if (\is_string($sourcePaths)) {
            $sourcePaths = [$sourcePaths];
        }

        // Recursively discover all files from repository paths
        $discoveredItems = $this->discoverRepositoryItems($owner, $repo, $sourcePaths);

        // Apply path-based filters
        $filteredItems = $this->applyFilters($discoveredItems);

        // Build result structure
        $files = [];
        $this->buildResultStructure($filteredItems, $owner, $repo, $files);


        $files = (new ContentsFilter(contains: $source->contains(), notContains: $source->notContains()))
            ->apply(
                $files,
            );

        $tree = \array_map(
            static fn(GithubFileInfo $file) => $file->getRelativePathname(),
            $files,
        );


        // Create the result
        return new FinderResult(
            new \ArrayIterator($files),
            (new FileTreeBuilder())->buildTree($tree, ''),
        );
    }

    /**
     * Set the GitHub API authentication token
     */
    public function setToken(?string $token): self
    {
        $this->githubToken = $token;
        return $this;
    }

    /**
     * Set the repository branch or tag
     */
    public function setBranch(string $branch): self
    {
        $this->branch = $branch;
        return $this;
    }

    /**
     * Get repository contents from the GitHub API
     */
    public function getContents(string $owner, string $repo, string $path = ''): array
    {
        $url = \sprintf(
            '/repos/%s/%s/contents/%s?ref=%s',
            \urlencode($owner),
            \urlencode($repo),
            $path ? \urlencode($path) : '',
            \urlencode($this->branch),
        );

        $response = $this->sendRequest('GET', $url);

        // Check if we got a single file or a directory
        if (isset($response['type']) && $response['type'] === 'file') {
            return [$response]; // Single file response
        }

        return $response; // Directory response (array of items)
    }

    /**
     * Apply all filters to the GitHub API response items
     */
    public function applyFilters(array $items): array
    {
        foreach ($this->filters as $filter) {
            $items = $filter->apply($items);
        }

        return $items;
    }

    /**
     * Initialize path filters based on source configuration
     *
     * @param FilterableSourceInterface $source Source with filter criteria
     */
    private function initializePathFilters(FilterableSourceInterface $source): void
    {
        // Clear existing filters
        $this->filters = [];

        // Add file name pattern filter
        $filePattern = $source->name();
        if ($filePattern) {
            $this->filters[] = new FilePatternFilter($filePattern);
        }

        // Add path inclusion filter
        $path = $source->path();
        if ($path) {
            $this->filters[] = new PathFilter($path);
        }

        // Add path exclusion filter
        $excludePatterns = $source->notPath();
        if ($excludePatterns) {
            $this->filters[] = new ExcludePathFilter($excludePatterns);
        }
    }

    /**
     * Discover all items from repository paths recursively
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param array<string> $sourcePaths Source paths to discover
     * @return array<array<string, mixed>> Discovered items
     */
    private function discoverRepositoryItems(string $owner, string $repo, array $sourcePaths): array
    {
        $allItems = [];

        foreach ($sourcePaths as $path) {
            $items = $this->fetchDirectoryContents($owner, $repo, $path);
            $allItems = \array_merge($allItems, $this->traverseDirectoryRecursively($items, $owner, $repo));
        }

        return $allItems;
    }

    /**
     * Traverse directory items recursively to discover all files
     */
    private function traverseDirectoryRecursively(array $items, string $owner, string $repo): array
    {
        $result = [];

        foreach ($items as $item) {
            if (($item['type'] ?? '') === 'dir') {
                $subItems = $this->fetchDirectoryContents($owner, $repo, $item['path']);
                $result = \array_merge($result, $this->traverseDirectoryRecursively($subItems, $owner, $repo));
            } else {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Build the final result structure (files and tree)
     */
    private function buildResultStructure(
        array $items,
        string $owner,
        string $repo,
        array &$files,
    ): void {
        foreach ($items as $item) {
            $path = $item['path'];

            try {
                $relativePath = \dirname((string) $path);
                if ($relativePath === '.') {
                    $relativePath = '';
                }

                // Add to files array
                $files[] = new GithubFileInfo(
                    $relativePath,
                    $path,
                    $item,
                    fn() => $this->fetchFileContent($owner, $repo, $path),
                );
            } catch (\Exception) {
                // Skip files that can't be processed
                continue;
            }
        }
    }

    /**
     * Fetch directory contents from GitHub API
     */
    private function fetchDirectoryContents(string $owner, string $repo, string $path = ''): array
    {
        $url = \sprintf(
            '/repos/%s/%s/contents/%s?ref=%s',
            \urlencode($owner),
            \urlencode($repo),
            $path ? \urlencode($path) : '',
            \urlencode($this->branch),
        );

        $response = $this->sendRequest('GET', $url);

        // Check if we got a single file or a directory
        if (isset($response['type']) && $response['type'] === 'file') {
            return [$response]; // Single file response
        }

        return $response; // Directory response (array of items)
    }

    /**
     * Fetch file content from GitHub API
     */
    private function fetchFileContent(string $owner, string $repo, string $path): string
    {
        $url = \sprintf(
            '/repos/%s/%s/contents/%s?ref=%s',
            \urlencode($owner),
            \urlencode($repo),
            \urlencode($path),
            \urlencode($this->branch),
        );

        $response = $this->sendRequest('GET', $url);

        if (!isset($response['content'])) {
            throw new \RuntimeException("Could not get content for file: $path");
        }

        // GitHub API returns base64 encoded content
        return \base64_decode((string) $response['content'], true);
    }

    /**
     * Parse repository string into owner and name
     *
     * @param string $repository Repository string in format "owner/repo"
     * @return array{0: string, 1: string} Repository owner and name
     * @throws \InvalidArgumentException If repository string is invalid
     */
    private function parseRepository(string $repository): array
    {
        if (!\preg_match('/^([^\/]+)\/([^\/]+)$/', $repository, $matches)) {
            throw new \InvalidArgumentException(
                "Invalid repository format: $repository. Expected format: owner/repo",
            );
        }

        return [$matches[1], $matches[2]];
    }

    /**
     * Send an HTTP request to the GitHub API
     *
     * @param string $method HTTP method
     * @param string $path API path
     * @return array<string, mixed> JSON response data
     * @throws \RuntimeException If the request fails
     */
    private function sendRequest(string $method, string $path): array
    {
        $url = self::API_BASE_URL . $path;
        $request = $this->requestFactory->createRequest($method, $url);

        // Add authentication if token is provided
        if ($this->githubToken) {
            $request = $this->addAuthHeader($request);
        }

        // Add headers
        $request = $request->withHeader('Accept', 'application/vnd.github.v3+json');
        $request = $request->withHeader('User-Agent', 'ContextGenerator');

        // Send the request
        try {
            $response = $this->httpClient->sendRequest($request);

            // Check for success status code
            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(
                    "GitHub API request failed with status code $statusCode: " . $response->getReasonPhrase(),
                );
            }

            // Parse JSON response
            $body = $response->getBody()->getContents();
            $data = \json_decode($body, true);

            if (\json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Failed to parse GitHub API response: ' . \json_last_error_msg());
            }

            return $data;
        } catch (\Throwable $e) {
            throw new \RuntimeException('GitHub API request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Add authentication header to request
     */
    private function addAuthHeader(RequestInterface $request): RequestInterface
    {
        return $request->withHeader('Authorization', 'token ' . $this->githubToken);
    }
}
