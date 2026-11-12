<?php

declare(strict_types=1);

namespace App\Module\File\Infrastructure;

/**
 * Where the bytes of the files are in the record_files storage: under a directory per tenant,
 * named after its id.
 *
 *     <tenant id>/<first two hex of the SHA-256>/<SHA-256>          a content
 *     <tenant id>/thumbnails/<size>/<SHA-256>.<format>              a thumbnail
 *
 * Within a tenant the same bytes are stored once; across tenants never shared, so that no tenant
 * learns what another stores, and one tenant's GC or deletion doesn't depend on the others.
 *
 * Files stored before tenants had directories have their key at the root (<aa>/<SHA-256>) until
 * tenant:files:move moves them; each row keeps the key it was stored under, so they stay readable.
 */
final class StorageKeys
{
    /** A content key under a tenant's directory: the SHA-256 in group 1. */
    public const string CONTENT = '~^[0-9a-f-]{36}/[0-9a-f]{2}/([0-9a-f]{64})$~';

    /** A thumbnail key under a tenant's directory: the SHA-256 in group 1. */
    public const string THUMBNAIL = '~^[0-9a-f-]{36}/thumbnails/[a-z]+/([0-9a-f]{64})\.[a-z]+$~';

    /** A content key of before tenants had directories: the SHA-256 in group 1. */
    public const string LEGACY_CONTENT = '~^[0-9a-f]{2}/([0-9a-f]{64})$~';

    /** The directory of the thumbnails of before tenants had directories. */
    public const string LEGACY_THUMBNAILS = 'thumbnails';

    public static function directory(string $tenantId): string
    {
        return strtolower($tenantId);
    }

    public static function content(string $tenantId, string $sha256): string
    {
        return \sprintf('%s/%s/%s', self::directory($tenantId), substr($sha256, 0, 2), $sha256);
    }

    public static function thumbnail(string $tenantId, string $size, string $sha256, string $format): string
    {
        return \sprintf('%s/thumbnails/%s/%s.%s', self::directory($tenantId), $size, $sha256, $format);
    }
}
