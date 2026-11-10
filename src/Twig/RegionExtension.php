<?php

namespace App\Twig;

use App\Service\Geo\SchoolRegionLabel;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the school's region word to templates as `{{ region_label() }}`:
 * "State" in Nigeria, "Governorate" in Iraq, "Province" in South Africa.
 *
 * The resolution (and its per-request cache) lives in
 * {@see SchoolRegionLabel} so form types can use the same word.
 */
final class RegionExtension extends AbstractExtension
{
    public function __construct(private readonly SchoolRegionLabel $regionLabel)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('region_label', [$this->regionLabel, 'label']),
        ];
    }
}
