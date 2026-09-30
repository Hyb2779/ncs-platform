<?php

namespace App\Http\Controllers\Casino;

use App\Http\Controllers\Controller;
use App\Services\Casino\ProviderRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** RomaSpin callback'leri {callback_url}/api/balance | /api/transaction | /api/batch-transaction adreslerine gelir. */
class RomaSpinCallbackController extends Controller
{
    public function __invoke(Request $request, ProviderRegistry $registry): Response
    {
        $driver = $registry->get('romaspin');
        abort_if($driver === null, 404);

        return $driver->handleCallback($request);
    }
}
