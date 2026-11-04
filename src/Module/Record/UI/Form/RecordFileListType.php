<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Form;

use App\Module\FieldType\Contract\Dto\FileUpload;
use App\Module\File\Contract\Dto\StoredFile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The control of a file field holding several files: the current files in their order, each
 * with "Remove" and buttons to move it up or down (the file-list Stimulus controller), and one
 * input adding files at the end of the list.
 *
 * The controller writes the ids kept, in order, into a hidden input; without JavaScript it holds
 * every current file, unchanged. Its data is what the record pipeline expects: the ids kept,
 * then a FileUpload per new file. Only the current ids from the options are taken from the
 * hidden input, so a form cannot attach somebody else's file to the record (and the record
 * pipeline checks it again).
 *
 * @extends AbstractType<list<FileUpload|string>>
 */
final class RecordFileListType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<StoredFile> $current */
        $current = $options['current'];
        $currentIds = array_map(static fn (StoredFile $file): string => $file->id, $current);
        $builder->add('kept', HiddenType::class);
        $builder->add('upload', FileType::class, [
            'label' => false,
            'multiple' => true,
            // Required only while there is nothing to keep; removing every file is refused by
            // the record pipeline then.
            'required' => $options['required'] && [] === $current,
            'attr' => array_filter(['accept' => $options['accept']]),
        ]);

        $builder->addModelTransformer(new CallbackTransformer(
            static fn (): array => ['kept' => implode(',', $currentIds), 'upload' => []],
            static function (mixed $data) use ($currentIds): array {
                $kept = \is_array($data) && \is_string($data['kept'] ?? null) ? explode(',', strtolower($data['kept'])) : [];
                $files = array_values(array_unique(array_intersect($kept, $currentIds)));
                $uploads = \is_array($data) && \is_array($data['upload'] ?? null) ? $data['upload'] : [];
                foreach ($uploads as $upload) {
                    if ($upload instanceof UploadedFile) {
                        $files[] = new FileUpload($upload->getPathname(), $upload->getClientOriginalName());
                    }
                }

                return $files;
            },
        ));
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        /** @var list<StoredFile> $current */
        $current = $options['current'];
        $attr = \is_array($view->vars['attr'] ?? null) ? $view->vars['attr'] : [];
        $view->vars['attr'] = [
            'class' => trim((\is_string($attr['class'] ?? null) ? $attr['class'] : '').' md-file-list'),
            'data-controller' => 'file-list',
        ] + $attr;
        $view->vars['current_files'] = array_map(
            static fn (StoredFile $file): array => ['file' => $file, 'size' => RecordFileType::size($file->size)],
            $current,
        );
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
            'current' => [],
            'accept' => '',
            // The errors of the record (type, size, count) stay on the field.
            'error_bubbling' => false,
        ]);
        $resolver->setAllowedTypes('current', StoredFile::class.'[]');
        $resolver->setAllowedTypes('accept', 'string');
    }

    public function getBlockPrefix(): string
    {
        return 'record_file_list';
    }
}
