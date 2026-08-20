<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApiDockAccess
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('api-dock.enabled', true), Response::HTTP_NOT_FOUND);

        return $next($request);
    }
}
