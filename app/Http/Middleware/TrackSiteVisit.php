<?php

namespace App\Http\Middleware;

use App\Models\SiteVisit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Jetstream\Agent;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records anonymous visits to the public site (landing pages and storefront).
 *
 * Only applies to routes explicitly protected with the "track.visit" alias,
 * so the authenticated app is never tracked.
 */
class TrackSiteVisit
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->shouldTrack($request, $response)) {
            $this->record($request);
        }

        return $response;
    }

    private function shouldTrack(Request $request, Response $response): bool
    {
        return $request->isMethod('GET')
            && $response->getStatusCode() < 400
            && !Auth::check()
            && !$request->hasHeader('X-Inertia-Partial-Data')
            && !$request->is('admin/*', 'api/*', 'up');
    }

    private function record(Request $request): void
    {
        try {
            $visitorHash = hash('sha256', ($request->ip() ?? 'unknown') . '|' . ($request->userAgent() ?? 'unknown'));

            // Ignore reloads from the same visitor on the same page within 30 minutes.
            $cacheKey = 'site-visit:' . $visitorHash . ':' . md5($request->path());

            if (!Cache::add($cacheKey, true, now()->addMinutes(30))) {
                return;
            }

            SiteVisit::create([
                'path' => '/' . ltrim($request->path(), '/'),
                'route_name' => $request->route()?->getName(),
                'visitor_hash' => $visitorHash,
                'referrer' => $request->headers->get('referer'),
                'device' => $this->detectDevice(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Unable to record site visit', ['error' => $e->getMessage()]);
        }
    }

    private function detectDevice(): string
    {
        $agent = new Agent();

        if ($agent->isMobile()) {
            return 'mobile';
        }

        if ($agent->isTablet()) {
            return 'tablet';
        }

        return 'desktop';
    }
}
