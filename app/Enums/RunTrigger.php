<?php

namespace App\Enums;

/**
 * Stored in runs.trigger, VARCHAR(8). Runs started by n8n arrive through
 * the API, so they are Api; scheduling belongs to n8n, not to this app.
 * Send is one request sent from the collection editor (D-038).
 */
enum RunTrigger: string
{
    case Manual = 'manual';
    case Api = 'api';
    case Send = 'send';
}
