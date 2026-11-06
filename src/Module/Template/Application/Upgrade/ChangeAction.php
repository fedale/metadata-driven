<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Upgrade;

enum ChangeAction: string
{
    case Add = 'add';
    case Change = 'change';
    case Remove = 'remove';
}
