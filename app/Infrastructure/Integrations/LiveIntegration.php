<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations;

/**
 * Outbound integrations gated by {@see LiveIntegrationGate}; each value is its key
 * under `integrations.live`.
 */
enum LiveIntegration: string
{
    case Shopwired = 'shopwired';
    case Linnworks = 'linnworks';
    case HelpScout = 'helpscout';
    case Slack = 'slack';
    case GoogleAds = 'google_ads';
    case BingAds = 'bing_ads';
    case Mixpanel = 'mixpanel';
    case ClickUp = 'clickup';
    case ReviewsIo = 'reviewsio';
    case RemoteStorage = 'remote_storage';
    case Doofinder = 'doofinder';
    case Sentry = 'sentry';
    case Supabase = 'supabase';

    public function optInSetting(): string
    {
        return \strtoupper($this->value) . '_LIVE';
    }
}
