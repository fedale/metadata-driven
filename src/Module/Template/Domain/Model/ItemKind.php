<?php

declare(strict_types=1);

namespace App\Module\Template\Domain\Model;

/**
 * The items of a template that carry an id of their own. Translations do not: their keys name
 * the items they belong to.
 */
enum ItemKind: string
{
    case Object = 'object';
    case Field = 'field';
    case View = 'view';
    case Form = 'form';
}
