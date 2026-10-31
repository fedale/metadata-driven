<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Twig;

use App\Module\Metadata\Contract\Dto\ViewDefinitionDto;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\FormView;
use Twig\Attribute\AsTwigFunction;
use Twig\Environment;

/**
 * Renders the layout of a form view: each {field} token becomes the row of that field.
 *
 * The rows placed by the layout are marked rendered, so form_rest() afterwards prints
 * only the fields the layout leaves out. A token for a field the form lacks is dropped.
 */
final class RecordFormLayout
{
    /**
     * @param string $template sanitized when the view was saved: printed as it is
     */
    #[AsTwigFunction('record_form_layout', needsEnvironment: true, isSafe: ['html'])]
    public function render(Environment $twig, FormView $form, string $template): string
    {
        $renderer = $twig->getRuntime(FormRenderer::class);

        return (string) preg_replace_callback(
            ViewDefinitionDto::TOKEN_PATTERN,
            static fn (array $token): string => isset($form[$token[1]]) ? $renderer->searchAndRenderBlock($form[$token[1]], 'row') : '',
            $template,
        );
    }

    /**
     * Renders the layout of a node of a composite form: a token becomes the row of a field
     * (under "values") or of a service field (under "service"), or the section already rendered
     * under that name. The rows placed are marked rendered, as above.
     *
     * @param string                            $template sanitized when the form was saved: printed as it is
     * @param array<string, string|\Stringable> $sections the HTML of each section, by name
     */
    #[AsTwigFunction('composite_form_layout', needsEnvironment: true, isSafe: ['html'])]
    public function renderComposite(Environment $twig, FormView $node, string $template, array $sections): string
    {
        $renderer = $twig->getRuntime(FormRenderer::class);

        return (string) preg_replace_callback(
            ViewDefinitionDto::TOKEN_PATTERN,
            static function (array $token) use ($renderer, $node, $sections): string {
                foreach (['values', 'service'] as $group) {
                    if (isset($node[$group]) && isset($node[$group][$token[1]])) {
                        return $renderer->searchAndRenderBlock($node[$group][$token[1]], 'row');
                    }
                }

                return (string) ($sections[$token[1]] ?? '');
            },
            $template,
        );
    }
}
