<?php

namespace App\Enums;

/**
 * Stored in collections.kind, VARCHAR(20). Metadata for filtering only —
 * every kind runs through the same Postman CLI engine (master prompt
 * section 20).
 */
enum CollectionKind: string
{
    case Smoke = 'smoke';
    case Integration = 'integration';
    case Regression = 'regression';
    case Other = 'other';
}
