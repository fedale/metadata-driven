<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\RecordAction;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(MetadataRegistryInterface $metadata, AccessCheckerInterface $access): Response
    {
        // Only the objects whose records the user may read.
        $objects = array_filter(
            $metadata->allObjects(),
            static fn ($object): bool => $access->canRecords($object->name, RecordAction::Read),
        );

        // The forms the user may fill in: creating records of every object they touch.
        $forms = array_filter(
            $metadata->allForms(),
            static function ($form) use ($access): bool {
                foreach ($form->root->objects() as $object) {
                    if (!$access->canRecords($object, RecordAction::Create)) {
                        return false;
                    }
                }

                return true;
            },
        );

        return $this->render('record/home.html.twig', ['objects' => $objects, 'forms' => $forms, 'can_administer' => $access->canAdminister()]);
    }
}
