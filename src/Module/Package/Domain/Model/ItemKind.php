<?php

declare(strict_types=1);

namespace App\Module\Package\Domain\Model;

/**
 * The items of a package that carry an id of their own. Translations do not: their keys name
 * the items they belong to.
 */
enum ItemKind: string
{
    case Object = 'object';
    case Field = 'field';
    case View = 'view';
    case Form = 'form';
    /** A workflow: its local id is the workflow's. */
    case Workflow = 'workflow';
}
