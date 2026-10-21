<?php


namespace App\Form\Tenant;

use App\Entity\Tenant\School;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
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
            ->add('institutionType', ChoiceType::class, [
                'label' => 'School type',
                'required' => true,
                'choices' => [
                    'Nursery — calls children “pupils”' => School::TYPE_NURSERY,
                    'Primary — calls children “pupils”' => School::TYPE_PRIMARY,
                    'Junior Secondary (JSS 1–3)' => School::TYPE_JUNIOR_SECONDARY,
                    'Senior Secondary (SS 1–3)' => School::TYPE_SENIOR_SECONDARY,
                    'Secondary — both junior and senior' => School::TYPE_SECONDARY,
                    'Primary & Secondary (combined)' => School::TYPE_COMBINED,
                    'College / Polytechnic' => School::TYPE_TERTIARY,
                ],
                'help' => 'This decides the section printed on the report card and whether children are called “pupils” or “students”.',
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
            ->add('feesSummary', TextareaType::class, [
                'label' => 'School fees summary',
                'required' => false,
                'attr' => ['rows' => 4, 'placeholder' => "e.g. JSS 1: ₦45,000 per term\nJSS 2: ₦48,000 per term"],
                'help' => 'Shown on the admissions page — one line per class. Leave empty to hide the fees section.',
            ])
            ->add('foundedYear', IntegerType::class, [
                'label' => 'Year the school was founded',
                'required' => false,
                'attr' => ['min' => 1800, 'max' => (int) date('Y'), 'placeholder' => 'e.g. 2009'],
                'help' => 'Shown as “Est. 2009” on your website. Leave empty to hide it.',
            ])
            ->add('facilities', TextareaType::class, [
                'label' => 'Facilities',
                'required' => false,
                'attr' => ['rows' => 4, 'placeholder' => "Science laboratory\nLibrary\nICT suite\nBoarding house"],
                'help' => 'One per line. Shown on the About page.',
            ])
            ->add('coreValues', TextareaType::class, [
                'label' => 'Your core values',
                'required' => false,
                'attr' => ['rows' => 5, 'placeholder' => "Integrity: We do the right thing even when no one is watching.\nExcellence: We hold every child to a standard worth reaching."],
                'help' => 'One per line, as “Title: what it means”. These are your words, shown on the About page.',
            ])
            ->add('showStudentCount', CheckboxType::class, [
                'label' => 'Show the number of pupils enrolled on the home page',
                'required' => false,
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
            ->add('whatsappNumber', TextType::class, [
                'label' => 'WhatsApp number',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. 08031234567'],
                'help' => 'Where parents should message you. Leave empty to use the phone number above.',
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
            ])

            // ================================================================
            // 🆕 APPLICATION MODE — parent self-registration & child application
            //================================================================
            ->add('applicationsOpen', CheckboxType::class, [
                'label' => 'Accept online applications',
                'required' => false,
                'help' => 'When on, parents can register an account and apply for a child from the school website.',
            ])
            ->add('applicationsOpenAt', DateType::class, [
                'label' => 'Applications open on',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'Shown to parents. (The switch above is what actually opens the form.)',
            ])
            ->add('applicationsCloseAt', DateType::class, [
                'label' => 'Applications close on',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('applicationFeeAmount', NumberType::class, [
                'label' => 'Application form fee',
                'required' => false,
                'scale' => 2,
                'input' => 'string',
                'attr' => ['step' => '0.01', 'min' => 0, 'placeholder' => '0'],
                'help' => 'Leave empty/0 for a free application. When a fee is set, the application is only processed after it is paid.',
            ])
            ->add('paymentMethod', ChoiceType::class, [
                'label' => 'School fees collection',
                'choices' => [
                    'Offline — parent transfers to the school account and uploads proof' => School::PAYMENT_OFFLINE,
                    'Online — parent pays through the payment gateway' => School::PAYMENT_ONLINE,
                    'Both — let the parent choose' => School::PAYMENT_BOTH,
                ],
                'required' => true,
            ])
            ->add('applicationRequireApproval', CheckboxType::class, [
                'label' => 'A bursar must review every application',
                'required' => false,
                'help' => 'Recommended. Turn off only if you want submissions to be approved automatically.',
            ])
            ->add('applicationInstructions', TextareaType::class, [
                'label' => 'What parents must prepare',
                'required' => false,
                'attr' => ['rows' => 5],
                'help' => 'Shown as a checklist on the public application page (e.g. birth certificate, passport photo, last result).',
            ])
            ->add('applicationClassesNote', TextType::class, [
                'label' => 'Classes currently open',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. JSS 1 and JSS 2 only'],
            ])
            ->add('notifyBursarOnApplication', CheckboxType::class, [
                'label' => 'SMS the bursar when an application is submitted',
                'required' => false,
            ])
            ->add('bursarNotificationPhone', TextType::class, [
                'label' => 'Bursar notification phone',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. 08031234567'],
            ])
            ->add('smsOnApplication', CheckboxType::class, [
                'label' => 'SMS the parent at every application step',
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