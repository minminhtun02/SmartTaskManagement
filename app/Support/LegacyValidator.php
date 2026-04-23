<?php

declare(strict_types=1);

namespace App\Support;

final class LegacyValidator
{
    public static function email(string $email): bool
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    public static function required(?string $value): bool
    {
        return trim((string) $value) !== '';
    }

    public static function dueDateValid(?string $dueDate): bool
    {
        if ($dueDate === null || trim($dueDate) === '') {
            return true;
        }

        $date = date_create($dueDate);
        if ($date === false) {
            return false;
        }

        $now = new \DateTime('today');

        return $date >= $now;
    }
}
