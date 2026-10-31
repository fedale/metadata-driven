<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Grid\Renderer;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Renders the cells of a grid column, picked by name in a grid view ("renderer: truncate").
 *
 * The metadata choose among the renderers registered here; they never carry code. Add one by
 * implementing this interface: it is tagged and listed by GridRenderers automatically.
 */
#[AutoconfigureTag(self::TAG)]
interface CellRendererInterface
{
    public const string TAG = 'app.grid_cell_renderer';

    /**
     * The name a grid view uses: lowercase letters, digits and underscores.
     */
    public function name(): string;

    /**
     * @param array<string, mixed> $options
     *
     * @return list<string> what is wrong with the options; empty when they are valid
     */
    public function optionErrors(array $options): array;

    /**
     * @param mixed                $value   the stored value (a select's value, a date...)
     * @param string               $text    the value as the grid shows it (a select's label, a relation's label)
     * @param array<string, mixed> $options checked by optionErrors() when the view was saved
     *
     * @return string HTML: the renderer escapes what it prints
     */
    public function render(mixed $value, string $text, array $options): string;
}
