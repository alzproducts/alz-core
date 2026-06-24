<?php

declare(strict_types=1);

namespace App\Application\Notifications\Enums;

/**
 * Target audience for a chat alert, selecting the destination channel.
 */
enum AlertAudience
{
    case Admin;
    case Manager;
}
