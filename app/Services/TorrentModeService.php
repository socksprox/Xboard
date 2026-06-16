<?php

namespace App\Services;

use App\Models\Server;

class TorrentModeService
{
    public const MODE_ALLOW = 'allow';
    public const MODE_BLOCK = 'block';
    public const MODE_REPORT = 'report';

    public const MODES = [
        self::MODE_ALLOW,
        self::MODE_BLOCK,
        self::MODE_REPORT,
    ];

    public static function normalize(?string $mode): ?string
    {
        if ($mode === null) {
            return null;
        }

        $mode = strtolower(trim($mode));
        if (!in_array($mode, self::MODES, true)) {
            return null;
        }

        return $mode;
    }

    public static function syncFromNode(Server $server, ?string $mode): void
    {
        $normalized = self::normalize($mode);
        if ($normalized === null) {
            return;
        }

        if ($server->torrent_mode === $normalized) {
            return;
        }

        $server->torrent_mode = $normalized;
        $server->save();
    }

    public static function isStrict(Server $server): bool
    {
        return $server->torrent_mode !== self::MODE_ALLOW;
    }
}
