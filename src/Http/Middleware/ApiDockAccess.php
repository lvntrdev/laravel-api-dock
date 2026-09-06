<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class ApiDockAccess
{
    /**
     * The ability consulted when `api-dock.gate.enabled` is on.
     */
    public const ABILITY = 'viewApiDock';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('api-dock.enabled', true), Response::HTTP_NOT_FOUND);

        // Optional authorization gate, off unless the application turns it on, so the
        // config-off path below is byte-for-byte the behaviour every existing install
        // already has.
        //
        // A denial answers 404 rather than 403 deliberately: it is the same response the
        // package gives when `enabled` is false, so a refusal never confirms that the
        // panel is served on this route.
        //
        // Fail-closed by construction. Laravel denies an ability nobody defined, and the
        // service provider additionally registers a deny-all `viewApiDock` when the host
        // has not, so an application that switches this on and never defines the ability
        // is refused instead of waved through. The gate cannot be enabled into a no-op.
        if ((bool) config('api-dock.gate.enabled', false)) {
            abort_unless(Gate::allows(self::ABILITY), Response::HTTP_NOT_FOUND);
        }

        return $next($request);
    }
}
