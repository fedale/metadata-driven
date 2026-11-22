<?php

declare(strict_types=1);

namespace App\Module\Admin\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Schema\Contract\DatabaseTablesInterface;
use App\Shared\Workspace\CurrentWorkspace;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The tables loaded into the data schema by hand, which no object owns yet: what they hold, and
 * whether they can become an object. Read only.
 */
#[Route('/admin/tables', name: 'admin_table_')]
final class AdminTableController extends AbstractController
{
    public function __construct(
        private readonly DatabaseTablesInterface $tables,
        private readonly AccessCheckerInterface $access,
        private readonly CurrentWorkspace $workspace,
    ) {
    }

    #[Route('/{table}', name: 'show', requirements: ['table' => '[^/]+'], methods: ['GET'])]
    public function show(string $table): Response
    {
        if (!$this->access->canAdminister()) {
            throw $this->createAccessDeniedException('No permission to manage the objects.');
        }
        $found = $this->tables->find($table) ?? throw $this->createNotFoundException(\sprintf('The data schema has no table "%s" that no object owns.', $table));

        return $this->render('admin/table_show.html.twig', ['table' => $found, 'data_schema' => $this->workspace->dataSchema()]);
    }
}
