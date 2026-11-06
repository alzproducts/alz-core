<?php

declare(strict_types=1);

/*
 * Live opt-ins for outbound integrations (ADR-0015). Outside production every
 * integration binds a recording fake or a fail-closed stub unless its flag is true.
 * intentional: FILTER_NULL_ON_FAILURE keeps an unparseable value null so the gate
 * fails closed on it; a (bool) cast would read any non-empty string as true.
 */
$flag = static fn(string $name): ?bool => \filter_var(\env($name, false), \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);

$extraHosts = \array_values(\array_filter(\array_map(
    \trim(...),
    \explode(',', (string) \env('LOCAL_ALLOWED_HOSTS', '')),
), static fn(string $host): bool => $host !== ''));

return [
    'live' => [
        'shopwired' => $flag('SHOPWIRED_LIVE'),
        'linnworks' => $flag('LINNWORKS_LIVE'),
        'helpscout' => $flag('HELPSCOUT_LIVE'),
        'slack' => $flag('SLACK_LIVE'),
        'google_ads' => $flag('GOOGLE_ADS_LIVE'),
        'bing_ads' => $flag('BING_ADS_LIVE'),
        'mixpanel' => $flag('MIXPANEL_LIVE'),
        'clickup' => $flag('CLICKUP_LIVE'),
        'reviewsio' => $flag('REVIEWSIO_LIVE'),
        'remote_storage' => $flag('REMOTE_STORAGE_LIVE'),
        'doofinder' => $flag('DOOFINDER_LIVE'),
        'sentry' => $flag('SENTRY_LIVE'),
        'supabase' => $flag('SUPABASE_LIVE'),
    ],

    'local_allowed_hosts' => [...['127.0.0.1', 'localhost', '::1'], ...$extraHosts],
];
