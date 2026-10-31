<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Slack;

/**
 * Escapes untrusted text for Slack mrkdwn and fallback text, so `<!channel>`
 * mentions and `<url|label>` links in user input render literally.
 *
 * @see https://api.slack.com/reference/surfaces/formatting#escaping
 */
final class SlackMrkdwn
{
    public static function escape(string $text): string
    {
        // intentional: `&` first, or the entities produced for `<` and `>` get double-escaped.
        return \str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
