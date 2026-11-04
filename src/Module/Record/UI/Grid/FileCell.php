<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Grid;

use App\Module\Record\Application\Grid\RecordGridSourceInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The cell of a file field: each file it holds as a link to /files/{id}, with the small
 * thumbnail of an image or an icon for anything else, and the original name.
 *
 * The names, with ", " between them, are the only text of the cell: an export, which strips
 * the tags, writes them.
 * Every file is served by FileController, which checks the user's permissions again.
 */
final class FileCell
{
    private const string ICON = '<svg class="md-file-cell__icon" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false">'
        .'<path d="M4 1.5h5L12.5 5v9.5h-8.5z M9 1.5V5h3.5" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>';

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param array<mixed> $row a row of the record grid source
     */
    public function render(string $field, array $row): string
    {
        $files = $row[$field.RecordGridSourceInterface::FILE_SUFFIX] ?? null;
        if (!\is_array($files)) {
            return '';
        }

        $links = [];
        foreach ($files as $file) {
            if (!\is_array($file) || !\is_string($file['id'] ?? null) || !\is_string($file['name'] ?? null)) {
                continue;
            }
            $links[] = $this->link($file['id'], $file['name'], true === ($file['image'] ?? false));
        }

        // A text separator, not a space: the export reads "a.pdf, b.pdf", as the grid views'
        // renderers do (RecordGridSourceInterface::LABEL_SUFFIX).
        return implode('<span class="md-file-cell__separator">, </span>', $links);
    }

    private function link(string $id, string $name, bool $image): string
    {
        $download = htmlspecialchars($this->urls->generate('file_download', ['id' => $id]), \ENT_QUOTES);
        $preview = $image
            ? \sprintf('<img class="md-file-cell__thumbnail" src="%s" alt="" loading="lazy">', htmlspecialchars($this->urls->generate('file_thumbnail', ['id' => $id, 'size' => 'small']), \ENT_QUOTES))
            : self::ICON;

        // An image opens in a tab of its own; anything else is downloaded (FileController sends
        // it as an attachment). Out of Turbo either way: a file is no page of the app.
        return \sprintf(
            '<a class="md-file-cell" href="%s" data-turbo="false"%s>%s<span class="md-file-cell__name">%s</span></a>',
            $download,
            $image ? ' target="_blank" rel="noopener"' : '',
            $preview,
            htmlspecialchars($name, \ENT_QUOTES),
        );
    }
}
