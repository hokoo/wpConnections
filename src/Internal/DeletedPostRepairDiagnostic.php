<?php

namespace iTRON\wpConnections\Internal;

final class DeletedPostRepairDiagnostic
{
    private const CATEGORIES = [
        'adapter',
        'cleanup',
        'ledger',
        'scheduler',
        'storage',
        'unknown',
    ];

    private string $category;
    private string $class;
    private string $code;
    private string $summary;

    public function __construct(string $category, string $class, string $code, string $summary)
    {
        $this->category = self::sanitizeCategory($category);
        $this->class = self::sanitizeClass($class);
        $this->code = self::sanitizeCode($code);
        $this->summary = self::sanitizeSummary($summary);
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getClass(): string
    {
        return $this->class;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    private static function sanitizeCategory(string $value): string
    {
        $value = strtolower(self::withoutUnsafeControls($value));
        $value = (string) preg_replace('/[^a-z0-9_-]+/', '_', $value);
        $value = trim($value, '_-');

        return in_array($value, self::CATEGORIES, true) ? $value : 'unknown';
    }

    private static function sanitizeClass(string $value): string
    {
        $value = self::withoutUnsafeControls($value);
        $value = (string) preg_replace('/@anonymous.*$/', '@anonymous', $value);
        if (
            191 < strlen($value) ||
            ! preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*(?:@anonymous)?$/D', $value)
        ) {
            return 'unknown';
        }

        return $value;
    }

    private static function sanitizeCode(string $value): string
    {
        $value = trim(self::withoutUnsafeControls($value));

        return preg_match('/^-?[0-9]{1,19}$/D', $value) ? $value : 'unknown';
    }

    private static function sanitizeSummary(string $value): string
    {
        unset($value);

        return '[diagnostic details redacted]';
    }

    private static function withoutUnsafeControls(string $value): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    }
}
