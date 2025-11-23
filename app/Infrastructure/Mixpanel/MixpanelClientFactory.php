<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel;

use App\Application\Contracts\MixpanelClientInterface;
use RuntimeException;

final class MixpanelClientFactory
{
    public static function create(): MixpanelClientInterface
    {
        $baseUrl = \config('mixpanel.base_url');
        $projectId = \config('mixpanel.project_id');
        $serviceAccountUsername = \config('mixpanel.service_account_username');
        $serviceAccountPassword = \config('mixpanel.service_account_password');
        $lookupTableId = \config('mixpanel.utm_campaign_lookup_table_id');

        if (!\is_string($baseUrl) || ($baseUrl === '')) {
            throw new RuntimeException('MIXPANEL_BASE_URL not configured');
        }
        if (!\is_string($projectId) || ($projectId === '')) {
            throw new RuntimeException('MIXPANEL_PROJECT_ID not configured');
        }
        if (!\is_string($serviceAccountUsername) || ($serviceAccountUsername === '')) {
            throw new RuntimeException('MIXPANEL_SERVICE_ACCOUNT_USERNAME not configured');
        }
        if (!\is_string($serviceAccountPassword) || ($serviceAccountPassword === '')) {
            throw new RuntimeException('MIXPANEL_SERVICE_ACCOUNT_PASSWORD not configured');
        }
        if (!\is_string($lookupTableId) || ($lookupTableId === '')) {
            throw new RuntimeException('MIXPANEL_UTM_CAMPAIGN_LOOKUP_TABLE_ID not configured');
        }

        return new MixpanelClient(
            mixpanelBaseUrl: $baseUrl,
            serviceAccountUsername: $serviceAccountUsername,
            serviceAccountPassword: $serviceAccountPassword,
            projectId: $projectId,
            lookupTableId: $lookupTableId,
        );
    }
}
