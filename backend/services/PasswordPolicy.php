<?php

class PasswordPolicy {
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 72;

    public static function error($password): ?string {
        if (!is_string($password) || $password === '') return 'Password is required.';
        $length = strlen($password);
        if ($length < self::MIN_LENGTH) return 'Password must be at least 12 characters.';
        if ($length > self::MAX_LENGTH) return 'Password must be 72 characters or fewer.';
        return null;
    }
}
