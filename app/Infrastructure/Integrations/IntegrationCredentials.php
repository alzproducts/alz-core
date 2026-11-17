<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations;

use App\Domain\Exceptions\InvalidConfigurationException;

/**
 * Secret credentials whose presence switches an integration on: all set binds the real client,
 * any blank binds a stand-in outside production. See docs/adr/0015-blank-credentials-switch-integrations-off.md.
 */
final class IntegrationCredentials
{
    public const array HELPSCOUT = [
        'helpscout.auth.appSecret' => 'HelpScout app secret (HELPSCOUT_APP_SECRET)',
    ];

    public const array SLACK = [
        'services.slack.notifications.bot_user_oauth_token' => 'Slack bot token (SLACK_BOT_USER_OAUTH_TOKEN)',
    ];

    public const array GOOGLE_ADS = [
        'google-ads.developer_token' => 'Google Ads developer token (GOOGLE_ADS_DEVELOPER_TOKEN)',
        'google-ads.client_secret' => 'Google Ads client secret (GOOGLE_ADS_CLIENT_SECRET)',
        'google-ads.refresh_token' => 'Google Ads refresh token (GOOGLE_ADS_REFRESH_TOKEN)',
    ];

    public const array BING_ADS = [
        'bing-ads.client_secret' => 'Bing Ads client secret (BING_ADS_CLIENT_SECRET)',
        'bing-ads.refresh_token' => 'Bing Ads refresh token (BING_ADS_REFRESH_TOKEN)',
        'bing-ads.developer_token' => 'Bing Ads developer token (BING_ADS_DEVELOPER_TOKEN)',
    ];

    public const array ALL = [...self::HELPSCOUT, ...self::SLACK, ...self::GOOGLE_ADS, ...self::BING_ADS];

    /**
     * Whether every credential is set. A blank one in production throws instead, because the boot
     * check is skipped under CI and a binding must never pick a stand-in there.
     *
     * @param array<string, string> $credentials config key => description
     *
     * @throws InvalidConfigurationException
     */
    public static function areSet(array $credentials): bool
    {
        $blankKey = \array_find_key($credentials, static fn(string $description, string $key): bool => self::isBlank(\config()->get($key)));

        if ($blankKey === null) {
            return true;
        }

        if (\app()->environment('production')) {
            throw new InvalidConfigurationException($blankKey, "{$credentials[$blankKey]} is blank; production never binds a stand-in.");
        }

        return false;
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === false;
    }
}
