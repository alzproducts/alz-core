<?php

declare(strict_types=1);

/**
 * Console Routes
 *
 * Schedule definitions have been extracted to dedicated service providers
 * in app/Providers/Schedule/ for better organization and scalability:
 *
 * - AdsScheduleServiceProvider: Google Ads, Bing Ads, Campaign Lookup
 * - FeedsScheduleServiceProvider: DooFinder product search feed
 * - LinnworksScheduleServiceProvider: Stock item sync
 * - MixpanelScheduleServiceProvider: Order sync to Mixpanel
 * - ShopwiredScheduleServiceProvider: Orders and customers sync
 *
 * Add new scheduled jobs to the appropriate provider, or create a new
 * provider if introducing a new integration.
 */
