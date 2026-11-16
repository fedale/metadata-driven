<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Admin;

use App\Module\Admin\UI\Form\TransitionListTransformer;
use PHPUnit\Framework\TestCase;

final class TransitionListTransformerTest extends TestCase
{
    public function testReadsTheLinesAndWritesThemBack(): void
    {
        $text = "send | Send | draft, review | sent | ROLE_CLERK, ROLE_BOSS\npay | Mark as paid | sent | paid";
        $transitions = (new TransitionListTransformer())->reverseTransform($text."\n\n");

        self::assertSame([
            ['name' => 'send', 'label' => 'Send', 'from' => ['draft', 'review'], 'to' => 'sent', 'roles' => ['ROLE_CLERK', 'ROLE_BOSS']],
            ['name' => 'pay', 'label' => 'Mark as paid', 'from' => ['sent'], 'to' => 'paid', 'roles' => []],
        ], $transitions);
        self::assertSame($text, (new TransitionListTransformer())->transform($transitions));
    }
}
