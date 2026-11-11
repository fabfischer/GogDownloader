<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Bytes this process holds in temporary files right now, found through its open file descriptors (Linux /proc),
 * including files that are already unlinked. Shows whether an HTTP client or SDK spooled a body to disk.
 */
final class TempFiles
{
    public static function bytesHeld(): int
    {
        $total = 0;
        $tmp = rtrim(sys_get_temp_dir(), '/') . '/';
        foreach (glob('/proc/self/fd/*') ?: [] as $fd) {
            $target = @readlink($fd);
            if ($target !== false && str_starts_with($target, $tmp)) {
                $stat = @stat($fd);
                $total += $stat === false ? 0 : $stat['size'];
            }
        }

        return $total;
    }

    public static function canInspect(): bool
    {
        return is_dir('/proc/self/fd');
    }
}
