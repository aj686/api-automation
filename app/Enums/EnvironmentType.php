<?php

namespace App\Enums;

/**
 * Stored in environments.type, VARCHAR(20).
 *
 * Environment *names* are free text chosen by the user (master prompt
 * section 18). The type only drives behaviour, and the only behaviour that
 * differs today is production safety (plan section 6).
 */
enum EnvironmentType: string
{
    case Local = 'local';
    case Development = 'development';
    case Staging = 'staging';
    case Uat = 'uat';
    case Other = 'other';
    case Production = 'production';

    public function isProduction(): bool
    {
        return $this === self::Production;
    }
}
