<?php

namespace App\Http\Controllers\Casino;

use App\Http\Controllers\Controller;
use App\Services\Casino\ProviderRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CallbackController extends Controller
{
    public function __invoke(Request $request, string $provider, ProviderRegistry $registry): Response
    {
        $driver = $registry->get($provider);

        if ($driver === null || ($provider === 'demo' && app()->isProduction())) {
            abort(404);
        }

        return $driver->handleCallback($request);
    }
}
