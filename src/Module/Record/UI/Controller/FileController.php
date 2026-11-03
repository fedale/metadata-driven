<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\RecordAction;
use App\Module\File\Contract\Dto\StoredFile;
use App\Module\File\Contract\Dto\ThumbnailSize;
use App\Module\File\Contract\Exception\FileNotFound;
use App\Module\File\Contract\FileStorageInterface;
use App\Module\File\Contract\ThumbnailerInterface;
use App\Module\Record\Domain\Repository\FileReferences;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Serves the files of the records to whoever may read a record holding them. A file nobody may
 * read is a 404, the same as an unknown one: its existence is not revealed.
 */
#[Route('/files/{id}', name: 'file_', requirements: ['id' => Requirement::UUID])]
final class FileController extends AbstractController
{
    /** Shown in the page; anything else, an SVG included, is downloaded and never runs here. */
    private const array INLINE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    // Short: a file a user may no longer read leaves the browser cache within the hour.
    private const int MAX_AGE = 3600;

    public function __construct(
        private readonly FileStorageInterface $files,
        private readonly ThumbnailerInterface $thumbnails,
        private readonly FileReferences $references,
        private readonly AccessCheckerInterface $access,
    ) {
    }

    #[Route('', name: 'download', methods: ['GET'])]
    public function download(string $id, Request $request): Response
    {
        $file = $this->readable($id);
        $inline = \in_array($file->mimeType, self::INLINE_TYPES, true);
        $headers = new Response();
        $this->secure($headers, $file, $file->sha256, $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT);
        if ($headers->isNotModified($request)) {
            return $headers;
        }

        // Opened before the response is sent: a missing content is a 404, not a broken download.
        try {
            $stream = $this->files->readStream($id);
        } catch (FileNotFound $e) {
            throw $this->createNotFoundException('File not found.', $e);
        }
        $response = new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, Response::HTTP_OK, $headers->headers->all());
        $response->headers->set('Content-Type', $file->mimeType);
        $response->headers->set('Content-Length', (string) $file->size);

        return $response;
    }

    #[Route('/thumbnail/{size}', name: 'thumbnail', requirements: ['size' => 'small|medium'], methods: ['GET'])]
    public function thumbnail(string $id, string $size, Request $request): Response
    {
        $file = $this->readable($id);
        $response = new Response();
        $this->secure($response, $file, $file->sha256.'-'.$size, ResponseHeaderBag::DISPOSITION_INLINE);
        if ($response->isNotModified($request)) {
            return $response;
        }

        try {
            $thumbnail = $this->thumbnails->thumbnail($id, ThumbnailSize::from($size));
        } catch (FileNotFound $e) {
            throw $this->createNotFoundException('File not found.', $e);
        }
        if (null === $thumbnail) {
            throw $this->createNotFoundException('This file has no thumbnail.');
        }
        $response->setContent($thumbnail->bytes);
        $response->headers->set('Content-Type', $thumbnail->mimeType);

        return $response;
    }

    private function readable(string $id): StoredFile
    {
        $file = $this->files->find($id);
        $readable = null !== $file && array_any(
            $this->references->objectsHolding($id),
            fn (string $object): bool => $this->access->canRecords($object, RecordAction::Read),
        );

        return $readable ? $file : throw $this->createNotFoundException('File not found.');
    }

    /**
     * The headers of every file response. A stored file never changes, so its hash is a strong
     * ETag; the cache is private, as every request is checked against the user's permissions.
     */
    private function secure(Response $response, StoredFile $file, string $etag, string $disposition): void
    {
        $response->setEtag($etag);
        $response->setLastModified($file->createdAt);
        $response->setPrivate();
        $response->setMaxAge(self::MAX_AGE);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Even opened directly, a file runs nothing: no scripts, no requests, a sandboxed origin.
        $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition($disposition, $file->originalName, self::asciiName($file->originalName)));
    }

    /**
     * The name for clients that do not read the UTF-8 one: printable ASCII, without the
     * characters a fallback name may not hold.
     */
    private static function asciiName(string $name): string
    {
        // One "_" per character, not per byte.
        $ascii = (string) preg_replace('~[^\x20-\x7E]|[%/\\\\"]~u', '_', $name);

        return '' !== trim($ascii, '_ ') ? $ascii : 'file';
    }
}
