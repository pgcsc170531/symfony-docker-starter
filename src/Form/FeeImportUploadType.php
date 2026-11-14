<?php

namespace App\Form;

use App\Entity\Tenant\Term;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

/**
 * The bursary opens one term and attaches the filled roster.
 *
 * The term is explicit because an opening balance belongs to one term; the
 * import never guesses the active term from the clock.
 */
class FeeImportUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('term', EntityType::class, [
                'class' => Term::class,
                'label' => 'Term',
                'placeholder' => 'Select the term being imported',
                'choice_label' => static fn (Term $term): string => sprintf(
                    '%s — %s',
                    $term->getSession()?->getName() ?? 'No session',
                    $term->getName() ?? 'Unnamed term'
                ),
                'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('t')
                    ->join('t.session', 's')
                    ->orderBy('s.name', 'DESC')
                    ->addOrderBy('t.startDate', 'DESC'),
            ])
            ->add('csvFile', FileType::class, [
                'label' => 'Fees CSV',
                'help' => 'Use the template below so the headings match.',
                'constraints' => [
                    new File(
                        maxSize: '10M',
                        mimeTypes: [
                            'text/csv',
                            'text/plain',
                            'application/csv',
                            'application/vnd.ms-excel',
                            'application/octet-stream',
                        ],
                        mimeTypesMessage: 'Upload the CSV file exported from the template.',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
