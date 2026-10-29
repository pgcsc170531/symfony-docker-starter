<?php

namespace App\Form;

use App\Entity\Tenant\AssessmentScheme;
use App\Entity\Tenant\School; // ✅ CORRECT
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
use Symfony\Component\Validator\Constraints\File;


class SchoolSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // === 1. GENERAL INFO ===
            ->add('name', TextType::class, ['label' => 'School Name'])
            ->add('institutionType', ChoiceType::class, [
                'label' => 'School Type',
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
            ->add('motto', TextType::class, ['label' => 'School Motto/Slogan', 'required' => false])
            ->add('email', EmailType::class, ['label' => 'Official Email'])
            ->add('phoneNumber', TextType::class, ['label' => 'Contact Number'])
            ->add('whatsappNumber', TextType::class, [
                'label' => 'WhatsApp Number',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. 08031234567'],
                'help' => 'Where parents should message you. Leave empty to use the contact number.',
            ])
            ->add('address', TextareaType::class, ['label' => 'Physical Address', 'attr' => ['rows' => 3]])
            ->add('website', UrlType::class, ['required' => false])
            ->add('feesSummary', TextareaType::class, [
                'label' => 'School Fees Summary (shown publicly)',
                'required' => false,
                'attr' => ['rows' => 4, 'placeholder' => "e.g. JSS 1: ₦45,000 per term\nJSS 2: ₦48,000 per term"],
                'help' => 'One line per class. Shown on the admissions page of your website.',
            ])
            ->add('foundedYear', IntegerType::class, [
                'label' => 'Year the school was founded',
                'required' => false,
                'attr' => ['min' => 1800, 'max' => (int) date('Y'), 'placeholder' => 'e.g. 2009'],
                'help' => 'Shown as “Est. 2009” on your website. Leave empty to hide it.',
            ])
            ->add('facilities', TextareaType::class, [
                'label' => 'Facilities (shown on your About page)',
                'required' => false,
                'attr' => ['rows' => 4, 'placeholder' => "Science laboratory\nLibrary\nICT suite\nBoarding house"],
                'help' => 'One per line.',
            ])
            ->add('coreValues', TextareaType::class, [
                'label' => 'Your core values (shown on your About page)',
                'required' => false,
                'attr' => ['rows' => 5, 'placeholder' => "Integrity: We do the right thing even when no one is watching.\nExcellence: We hold every child to a standard worth reaching."],
                'help' => 'One per line, as “Title: what it means”. These are your words.',
            ])
            ->add('showStudentCount', CheckboxType::class, [
                'label' => 'Show the number of pupils enrolled on the home page',
                'required' => false,
            ])
            
            // === 2. BRANDING ===
            ->add('primaryColor', ColorType::class, [
                'label' => 'Brand Color (for ID Cards & Header)',
                'required' => false,
            ])
            ->add('logoFile', FileType::class, [
                'label' => 'School Logo (PNG/JPG)',
                'mapped' => false,
                'required' => false,
                'help' => 'PNG or JPG, up to 2 MB. Until a logo is uploaded, report cards show the school’s initial.',
                'constraints' => [
                    new File([
                        'maxSize' => '2M',
                        'mimeTypes' => ['image/jpeg', 'image/png'],
                    ])
                ],
            ])

            // === 2b. REPORT CARD SIGNATURES & RELEASE ===
            ->add('principalName', TextType::class, [
                'label' => 'Principal / Head Teacher Name',
                'required' => false,
                'help' => 'Printed on the report card above the principal signature line.',
            ])
            ->add('principalTitle', TextType::class, [
                'label' => 'Principal Title',
                'required' => false,
                'attr' => ['placeholder' => 'Principal'],
            ])
            ->add('principalSignatureFile', FileType::class, [
                'label' => 'Principal Signature (PNG/JPG)',
                'mapped' => false,
                'required' => false,
                'help' => 'Optional. A scanned signature is placed above the printed name.',
                'constraints' => [
                    new File([
                        'maxSize' => '1M',
                        'mimeTypes' => ['image/jpeg', 'image/png'],
                    ])
                ],
            ])
            ->add('reportFeeGateEnabled', CheckboxType::class, [
                'label' => 'Withhold reports while fees are outstanding',
                'required' => false,
                'help' => 'A parent sees a clear “report available when fees are cleared” page instead of the report. Sharing is blocked too.',
            ])
            // How many CA tests this school runs. The teacher's entry sheet and
            // the report card both read this, so a school that sets two never sees
            // an empty "CA 3" column it cannot fill.
            ->add('caCount', ChoiceType::class, [
                'label' => 'Continuous assessment per subject',
                'required' => true,
                'choices' => [
                    'Two CA tests — the teacher enters 2 fields (CA 1, CA 2)' => AssessmentScheme::CA_COUNT_TWO,
                    'Three CA tests — the teacher enters 3 fields (CA 1, CA 2, CA 3)' => AssessmentScheme::CA_COUNT_THREE,
                ],
                'help' => 'Pick what your school actually runs. The teacher’s score-entry screen shows exactly this many CA fields, '
                    . 'and the report card prints exactly this many CA columns. Each CA is scored out of 20, and the examination '
                    . 'carries the rest. Changing this does not delete marks already entered for a dropped CA — they are simply '
                    . 'no longer counted.',
            ])

            // === 3. 🏦 BANKING DETAILS (New Section) ===
            ->add('bankName', TextType::class, [
                'label' => 'Bank Name',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. Zenith Bank'],
                'help' => 'This appears on generated payment slips.'
            ])
            ->add('accountNumber', TextType::class, [
                'label' => 'Account Number',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. 1012345678']
            ])
            ->add('accountName', TextType::class, [
                'label' => 'Account Name',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. Hope High School Operations']
            ])
            ->add('smsOnEnrollment', CheckboxType::class, [
                'label' => 'New Enrollment Alerts',
                'required' => false,
                'help' => 'Send SMS to parents when a new student is enrolled (₦15.00/unit).'
            ])
            ->add('smsOnFeePayment', CheckboxType::class, [
                'label' => 'Fee Payment Receipts',
                'required' => false,
                'help' => 'Send an automated receipt to parents upon fee payment (₦15.00/unit).'
            ])
            ->add('smsOnCalendarEvent', CheckboxType::class, [
                'label' => 'Calendar & Event Reminders',
                'required' => false,
                'help' => 'Notify parents about school events and holidays (₦15.00/unit).'
            ])

            // === 4. 📧 EMAIL NOTIFICATIONS ===
            // Each trigger has its own switch so a school can send only what it
            // wants (email sending is not free at scale).
            ->add('emailNotificationsEnabled', CheckboxType::class, [
                'label' => 'Send notification emails to parents',
                'required' => false,
                'help' => 'Master switch. When off, none of the emails below are sent.',
            ])
            ->add('emailOnApplicationSubmitted', CheckboxType::class, [
                'label' => 'Application received (acknowledgement + reference)',
                'required' => false,
            ])
            ->add('emailOnAdmissionDecision', CheckboxType::class, [
                'label' => 'Admission decision / child enrolled',
                'required' => false,
            ])
            ->add('emailOnInvoiceIssued', CheckboxType::class, [
                'label' => 'Invoice issued or fee reminder',
                'required' => false,
            ])
            ->add('emailOnPaymentReceipt', CheckboxType::class, [
                'label' => 'Payment receipt',
                'required' => false,
            ])
            ->add('emailOnResultPublished', CheckboxType::class, [
                'label' => 'Result published',
                'required' => false,
            ])

            // === 5. APPLICATION MODE ===
            // These fields are rendered by the general settings template; keep
            // the two settings forms in step so neither silently 500s.
            ->add('applicationsOpen', CheckboxType::class, [
                'label' => 'Accept online applications',
                'required' => false,
                'help' => 'When on, parents can register an account and apply for a child from the school website.',
            ])
            ->add('applicationsOpenAt', DateType::class, [
                'label' => 'Applications open on',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'Shown to parents. The switch above is what actually opens the form.',
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
            ->add('admissionNumberPattern', TextType::class, [
                'label' => 'Admission number pattern',
                'required' => false,
                'attr' => ['placeholder' => 'ADM/{YEAR}/{SEQ}', 'maxlength' => 60],
                'help' => 'How this school writes admission numbers. {YEAR} = 2026, {YY} = 26, '
                    . '{SEQ} = next number ({SEQ:3} pads to three digits). Leave empty for ADM/{YEAR}/{SEQ}.',
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
            ->add('onlineProcessingFeeEnabled', CheckboxType::class, [
                'label' => 'Parent pays the online payment charge',
                'required' => false,
                'help' => 'On: the parent pays the fee plus the gateway charge — 1% capped at ₦300, '
                    . 'plus 7.5% VAT on it, plus ₦200 handling, and never more than ₦500. '
                    . 'The school receives the full fee. Off: the parent pays the fee exactly, '
                    . 'and the gateway charge comes out of the school’s settlement instead. '
                    . 'Applies to school fees and the application form fee alike.',
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
            ])

        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => School::class]);
    }
}