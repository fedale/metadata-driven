<?php

declare(strict_types=1);

namespace App\Module\Package\UI\Form;

use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The form uploading a package archive: shown on the packages page, read where it posts.
 */
final class PackageUploadForm
{
    public function __construct(
        private readonly FormFactoryInterface $forms,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return FormInterface<mixed>
     */
    public function create(): FormInterface
    {
        return $this->forms->createNamedBuilder('upload', FormType::class, null, ['action' => $this->urls->generate('admin_package_upload')])
            ->add('archive', FileType::class, [
                'label' => 'admin.package_admin.upload_file',
                'help' => 'admin.package_admin.upload_help',
                // As large as a download may be (app.package.max_download_mb): checked again there.
                'constraints' => [new Assert\NotNull(), new Assert\File(maxSize: '50M')],
                'attr' => ['accept' => '.tar.gz,.tgz,application/gzip'],
            ])
            ->getForm();
    }
}
