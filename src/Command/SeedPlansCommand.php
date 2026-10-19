<?php

namespace App\Command;

use App\Entity\Landlord\Plan;
use App\Service\Pricing\PricingCatalog;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates (or refreshes) the two live subscription tracks from PricingCatalog.
 *
 * Idempotent: safe to run on every deploy. It never deletes a plan, so legacy
 * Starter/Growth/Scale rows and their grandfathered subscriptions survive.
 *
 *     docker compose exec -T php php bin/console app:landlord:seed-plans
 */
#[AsCommand(
    name: 'app:landlord:seed-plans',
    description: 'Create or refresh the Standard (per pupil) and Enterprise (licence) plans.'
)]
class SeedPlansCommand extends Command
{
    public function __construct(private readonly ManagerRegistry $doctrine)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $em = $this->doctrine->getManager('landlord');
        $repository = $em->getRepository(Plan::class);

        $standard = $repository->findOneBy(['pricingModel' => 'per_student'])
            ?? $repository->findOneBy(['name' => 'Standard'])
            ?? new Plan();
        $standard
            ->setName('Standard')
            ->setPricingModel('per_student')
            ->setPrice('0.00')
            ->setDurationMonths(4)
            ->setMinStudents(0)
            ->setMaxStudents(null)
            ->setIsTrial(false)
            ->setTermPricePerStudent((string) PricingCatalog::TERM_RATE)
            ->setAnnualPricePerStudent((string) PricingCatalog::ANNUAL_TERM_RATE)
            ->setMinimumTermInvoice((string) PricingCatalog::TERMLY_MINIMUM)
            ->setMinimumAnnualInvoice((string) PricingCatalog::ANNUAL_MINIMUM)
            ->setLicenceFee(null)
            ->setAnnualFee(null)
            ->setImplementationFee(null)
            ->setIsPublic(true)
            ->setFeatures([
                'Every module: admissions, fees, results, store, portal',
                'Unlimited staff accounts',
                'Email notifications included',
                'Term registration — your roll sets the bill',
            ]);

        $enterprise = $repository->findOneBy(['pricingModel' => 'licence'])
            ?? $repository->findOneBy(['name' => 'Enterprise'])
            ?? new Plan();
        $enterprise
            ->setName('Enterprise')
            ->setPricingModel('licence')
            ->setPrice('0.00')
            ->setDurationMonths(12)
            ->setMinStudents(0)
            ->setMaxStudents(null)
            ->setIsTrial(false)
            ->setTermPricePerStudent(null)
            ->setAnnualPricePerStudent(null)
            ->setMinimumTermInvoice(null)
            ->setMinimumAnnualInvoice(null)
            ->setLicenceFee((string) PricingCatalog::LICENCE_FEE)
            ->setAnnualFee((string) PricingCatalog::ENTERPRISE_ANNUAL_FEE)
            ->setImplementationFee((string) PricingCatalog::IMPLEMENTATION_FEE)
            ->setIsPublic(true)
            ->setFeatures([
                'Unlimited pupils — the roll is never invoiced',
                'SSO, API access and your own domain',
                'Migration, data validation and staff training',
                'Multi-campus roll and reporting',
                'Named success manager and SLA',
            ]);

        $em->persist($standard);
        $em->persist($enterprise);
        $em->flush();

        $io->success(sprintf(
            'Standard: ₦%s/pupil per term, ₦%s/pupil per year (min ₦%s/₦%s). Enterprise: ₦%s + ₦%s/year + ₦%s.',
            number_format(PricingCatalog::TERM_RATE),
            number_format(PricingCatalog::ANNUAL_TERM_RATE * PricingCatalog::TERMS_PER_YEAR),
            number_format(PricingCatalog::TERMLY_MINIMUM),
            number_format(PricingCatalog::ANNUAL_MINIMUM),
            number_format(PricingCatalog::LICENCE_FEE),
            number_format(PricingCatalog::ENTERPRISE_ANNUAL_FEE),
            number_format(PricingCatalog::IMPLEMENTATION_FEE),
        ));

        return Command::SUCCESS;
    }
}
