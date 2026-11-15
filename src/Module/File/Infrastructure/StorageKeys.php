<?php

declare(strict_types=1);

namespace App\Module\File\Infrastructure;

/**
 * Where the bytes of the files are in the record_files storage: under a directory per workspace,
 * named after its id.
 *
 *     <workspace id>/<first two hex of the SHA-256>/<SHA-256>          a content
 *     <workspace id>/thumbnails/<size>/<SHA-256>.<format>              a thumbnail
 *
 * Within a workspace the same bytes are stored once; across workspaces never shared, so that no workspace
 * learns what another stores, and one workspace's GC or deletion doesn't depend on the others.
 *
 * Files stored before workspaces had directories have their key at the root (<aa>/<SHA-256>) until
 * workspace:files:move moves them; each row keeps the key it was stored under, so they stay readable.
 */
final class StorageKeys
{
    /** A content key under a workspace's directory: the SHA-256 in group 1. */
    public const string CONTENT = '~^[0-9a-f-]{36}/[0-9a-f]{2}/([0-9a-f]{64})$~';

    /** A thumbnail key under a workspace's directory: the SHA-256 in group 1. */
    public const string THUMBNAIL = '~^[0-9a-f-]{36}/thumbnails/[a-z]+/([0-9a-f]{64})\.[a-z]+$~';

    /** A content key of before workspaces had directories: the SHA-256 in group 1. */
    public const string LEGACY_CONTENT = '~^[0-9a-f]{2}/([0-9a-f]{64})$~';

    /** The directory of the thumbnails of before workspaces had directories. */
    public const string LEGACY_THUMBNAILS = 'thumbnails';

    public static function directory(string $workspaceId): string
    {
        return strtolower($workspaceId);
    }

    public static function content(string $workspaceId, string $sha256): string
    {
        return \sprintf('%s/%s/%s', self::directory($workspaceId), substr($sha256, 0, 2), $sha256);
    }

    public static function thumbnail(string $workspaceId, string $size, string $sha256, string $format): string
    {
        return \sprintf('%s/thumbnails/%s/%s.%s', self::directory($workspaceId), $size, $sha256, $format);
    }
}
