<?php

final class MoneyValidator {
    public const MAX_AMOUNT = 999999999999.99;

    public static function parse($raw, bool $allowZero, string $label): float {
        if (!is_int($raw) && !is_float($raw) && !is_string($raw)) {
            throw new InvalidArgumentException("{$label} must be a finite number");
        }
        $text = trim((string)$raw);
        if (!preg_match('/^(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/', $text)) {
            throw new InvalidArgumentException("{$label} must use at most two decimal places");
        }
        $value = (float)$text;
        if (!is_finite($value) || (!$allowZero && $value <= 0) || ($allowZero && $value < 0) || $value > self::MAX_AMOUNT) {
            throw new InvalidArgumentException("{$label} is outside the supported range");
        }
        return $value;
    }

    public static function parseSigned($raw, string $label): float {
        if (!is_int($raw) && !is_float($raw) && !is_string($raw)) {
            throw new InvalidArgumentException("{$label} must be a finite number");
        }
        $text = trim((string)$raw);
        if (!preg_match('/^-?(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/', $text)) {
            throw new InvalidArgumentException("{$label} must use at most two decimal places");
        }
        $value = (float)$text;
        if (!is_finite($value) || abs($value) > self::MAX_AMOUNT) {
            throw new InvalidArgumentException("{$label} is outside the supported range");
        }
        return $value;
    }
}
