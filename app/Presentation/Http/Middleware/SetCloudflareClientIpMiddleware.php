<?php

declare(strict_types=1);

namespace App\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rewrites X-Forwarded-For with the genuine client IP from Cloudflare.
 *
 * The API sits behind Cloudflare's proxy, adding a hop
 * (client → Cloudflare → Railway → app). Railway's edge overwrites
 * X-Forwarded-For with its immediate upstream — a rotating Cloudflare egress
 * IP — so with `trustProxies(at: '*')` every $request->ip() read resolves to
 * Cloudflare, not the visitor. The true client survives only in Cloudflare's
 * CF-Connecting-IP header (Railway passes it through untouched); folding it
 * back into X-Forwarded-For restores $request->ip() for all downstream
 * consumers (rate limiting, security logs, basket-snapshot IP capture).
 *
 * Prepended to run first in the global stack so the rewrite is in place before
 * any $request->ip() read. No-op when the header is absent or not a valid public
 * IP (local dev, direct-to-origin hits), so it is safe to apply app-wide.
 *
 * Trade-off (accepted): a request reaching Railway directly, bypassing
 * Cloudflare, could forge CF-Connecting-IP. Tolerable — the resulting IP feeds
 * fuzzy order matching, rate limiting and logging, never authorization.
 */
final class SetCloudflareClientIpMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $clientIp = $request->headers->get('CF-Connecting-IP');

        // A genuine Cloudflare client is always a public address; reject private
        // and reserved ranges so a forged loopback/LAN IP from a direct-to-origin
        // request can't be folded into X-Forwarded-For.
        if (\is_string($clientIp) && \filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            $request->headers->set('X-Forwarded-For', $clientIp);
        }

        return $next($request);
    }
}
