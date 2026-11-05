<?php

declare(strict_types=1);

namespace App\TemplateFormat\FieldTypes;

/**
 * The object a field-reference setting points into.
 */
enum FieldOwner: string
{
    /** The object of the field the setting belongs to (a cascade's dependsOn). */
    case Own = 'self';
    /** The object named by the setting "target" (a cascade's dependsVia). */
    case Target = 'target';
    /** The object named by the setting "through" (a junction's two relation fields). */
    case Through = 'through';
}
