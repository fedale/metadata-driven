<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Form;

use App\Module\FieldType\Contract\Dto\FileUpload;
use App\Module\File\Contract\Dto\StoredFile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The control of a file field: the current file, an input to upload another one, and "Remove"
 * when the field may be empty.
 *
 * Its data is what the record pipeline expects: a FileUpload for a new file, the id of the
 * current file when nothing changes, null once removed. The current id comes from the options,
 * never from the request, so a form cannot point a record at somebody else's file.
 *
 * @extends AbstractType<FileUpload|string|null>
 */
final class RecordFileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var StoredFile|null $current */
        $current = $options['current'];
        $builder->add('upload', FileType::class, [
            'label' => false,
            // Required only while there is nothing to keep.
            'required' => $options['required'] && null === $current,
            'attr' => array_filter(['accept' => $options['accept']]),
        ]);
        // A required file may be replaced, never removed.
        if (null !== $current && !$options['required']) {
            $builder->add('remove', CheckboxType::class, ['label' => 'form.file_remove', 'required' => false]);
        }

        $builder->addModelTransformer(new CallbackTransformer(
            static fn (): array => ['upload' => null, 'remove' => false],
            static function (mixed $data) use ($current): FileUpload|string|null {
                $upload = \is_array($data) ? ($data['upload'] ?? null) : null;
                if ($upload instanceof UploadedFile) {
                    return new FileUpload($upload->getPathname(), $upload->getClientOriginalName());
                }

                return \is_array($data) && true === ($data['remove'] ?? false) ? null : $current?->id;
            },
        ));
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        /** @var StoredFile|null $current */
        $current = $options['current'];
        $attr = \is_array($view->vars['attr'] ?? null) ? $view->vars['attr'] : [];
        $view->vars['attr'] = ['class' => trim((\is_string($attr['class'] ?? null) ? $attr['class'] : '').' md-file')] + $attr;
        $view->vars['current_file'] = $current;
        $view->vars['current_size'] = null !== $current ? self::size($current->size) : null;
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        // The label of the field names the file input, as it would for a simple control.
        $labelAttr = \is_array($view->vars['label_attr'] ?? null) ? $view->vars['label_attr'] : [];
        $view->vars['label_attr'] = ['for' => $view['upload']->vars['id']] + $labelAttr;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'current' => null,
            'accept' => '',
            // The errors of the record (type, size) stay on the field, not on the whole form.
            'error_bubbling' => false,
        ]);
        $resolver->setAllowedTypes('current', ['null', StoredFile::class]);
        $resolver->setAllowedTypes('accept', 'string');
    }

    public function getBlockPrefix(): string
    {
        return 'record_file';
    }

    /**
     * A size in decimal units (1 kB = 1000 bytes), as the size limit of the field counts them.
     * RecordFileListType shows its files' sizes the same way.
     */
    public static function size(int $bytes): string
    {
        return match (true) {
            $bytes < 1000 => \sprintf('%d B', $bytes),
            $bytes < 1_000_000 => \sprintf('%d kB', (int) round($bytes / 1000)),
            default => \sprintf('%.1f MB', $bytes / 1_000_000),
        };
    }
}
