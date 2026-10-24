<?php

namespace App\Twig;

use App\Service\Payment\ProcessingFeeCalculator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `{{ payment_channels.card }}` — what a card costs versus a transfer, worded
 * from the constants in ProcessingFeeCalculator so the advice on screen cannot
 * drift away from the rate the code actually charges.
 *
 * Used by `templates/tenant/parent/_channel_note.html.twig`, which appears
 * anywhere a "Pay online" button does.
 */
final class PaymentExtension extends AbstractExtension
{
    public function __construct(private readonly ProcessingFeeCalculator $calculator)
    {
    }

    public function getFunctions(): array
    {
        return [
            // The flat charge on every invoice payment (₦750). `payment_cap` is
            // kept as an alias so older templates do not break.
            new TwigFunction('payment_transfer_charge', fn (): string => $this->calculator->maxFeeLabel()),
            new TwigFunction('payment_cap', fn (): string => $this->calculator->maxFeeLabel()),
            // Our net on the online application fee, added after Paystack's cut.
            new TwigFunction('payment_application_margin', fn (): string => '₦' . number_format(\App\Service\Payment\ProcessingFeeCalculator::APPLICATION_MARGIN, 0)),
            new TwigFunction('payment_channels', fn (): array => $this->calculator->channelRates()),
        ];
    }
}
