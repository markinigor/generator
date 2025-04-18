<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Application\Bootloader;

use Butschster\ContextGenerator\Lib\Content\ContentBuilderFactory;
use Butschster\ContextGenerator\Lib\Content\Renderer\MarkdownRenderer;
use Butschster\ContextGenerator\Lib\Content\Renderer\RendererInterface;
use Spiral\Boot\Bootloader\Bootloader;

final class ContentRendererBootloader extends Bootloader
{
    #[\Override]
    public function defineSingletons(): array
    {
        return [
            RendererInterface::class => MarkdownRenderer::class,
            ContentBuilderFactory::class => ContentBuilderFactory::class,
        ];
    }
}
