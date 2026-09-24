<?php


namespace App\Form\Tenant;

use App\Entity\Tenant\School;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SchoolWebsiteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'School Name',
                'required' => true,
            ])
            ->add('motto', TextType::class, [
                'label' => 'Motto',
                'required' => false,
            ])
            ->add('heroTagline', TextType::class, [
                'label' => 'Hero Tagline',
                'help' => 'Short text shown under the school name on the homepage.',
                'required' => false,
            ])
            ->add('primaryColor', ColorType::class, [
                'label' => 'Brand / Primary Colour',
                'required' => false,
            ])
            ->add('logoFile', FileType::class, [
                'label' => 'School Logo',
                'mapped' => false,
                'required' => false,
                'help' => 'Leave empty to keep the current logo.',
            ])
            ->add('heroImageFile', FileType::class, [
                'label' => 'Homepage Hero Image',
                'mapped' => false,
                'required' => false,
                'help' => 'Optional background image for the homepage hero.',
            ])
            ->add('aboutContent', TextareaType::class, [
                'label' => 'About Content',
                'required' => false,
                'attr' => ['rows' => 6],
            ])
            ->add('admissionsIntro', TextareaType::class, [
                'label' => 'Admissions Intro',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('mapEmbedUrl', TextareaType::class, [
                'label' => 'Google Map Embed Code',
                'required' => false,
                'help' => 'Paste the full iframe embed snippet from Google Maps.',
                'attr' => ['rows' => 4],
            ])
            ->add('address', TextareaType::class, [
                'label' => 'Address',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('phoneNumber', TextType::class, [
                'label' => 'Phone Number',
                'required' => false,
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'required' => false,
            ])
            ->add('website', UrlType::class, [
                'label' => 'Website',
                'required' => false,
            ])
            ->add('facebookUrl', UrlType::class, [
                'label' => 'Facebook URL',
                'required' => false,
            ])
            ->add('twitterUrl', UrlType::class, [
                'label' => 'Twitter / X URL',
                'required' => false,
            ])
            ->add('instagramUrl', UrlType::class, [
                'label' => 'Instagram URL',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => School::class,
        ]);
    }
}