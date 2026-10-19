<?php

namespace App\Twig;

use App\Service\Branding\BrandColor;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * `{{ brand|brand_on_white }}` — the school's colour, darkened only as far as
 * it takes to make white text on it legible. See App\Service\Branding\BrandColor.
 */
final class BrandExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('brand_on_white', [BrandColor::class, 'onWhite']),
            new TwigFunction('brand_contrast', [BrandColor::class, 'contrastWithWhite']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('brand_on_white', [BrandColor::class, 'onWhite']),
        ];
    }
}
