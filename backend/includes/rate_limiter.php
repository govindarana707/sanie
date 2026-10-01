<?php

class RateLimiter {
    private static function path($key) {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'sanie-rate-' . hash('sha256', $key) . '.json';
    }

    public static function hit($key, $limit = 5, $windowSeconds = 300) {
        return self::record($key, $limit, $windowSeconds, true);
    }

    public static function hitStrict($key, $limit = 5, $windowSeconds = 300) {
        return self::record($key, $limit, $windowSeconds, false);
    }

    private static function record($key, $limit, $windowSeconds, $failOpen) {
        $path = self::path($key);
        $handle = fopen($path, 'c+');
        if (!$handle) return (bool)$failOpen;

        try {
            flock($handle, LOCK_EX);
            $raw = stream_get_contents($handle);
            $record = $raw ? json_decode($raw, true) : null;
            $now = time();
            if (!is_array($record) || ($record['started_at'] ?? 0) <= $now - $windowSeconds) {
                $record = ['started_at' => $now, 'count' => 0];
            }
            $record['count']++;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($record));
            return $record['count'] <= $limit;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public static function clear($key) {
        $path = self::path($key);
        if (is_file($path)) @unlink($path);
    }
}
