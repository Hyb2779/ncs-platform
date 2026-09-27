<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

/** "Wegas Spor": Tipo sporu, NCS VIP köprüsü üzerinden iframe. */
class WegasSportController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        abort_unless(wegas_sport_available($user), 404);

        $url = null;
        try {
            $raw = json_encode(['user_id' => $user->id, 'name' => $user->username, 'lang' => $user->language->value]);
            $ts = (string) time();
            $response = Http::withHeaders([
                'X-Bridge-Timestamp' => $ts,
                'X-Bridge-Signature' => hash_hmac('sha256', $ts.'.'.$raw, (string) config('services.ncs_bridge.secret')),
                'Accept' => 'application/json',
            ])->timeout(15)->withBody($raw, 'application/json')
                ->post(rtrim((string) config('services.ncs_bridge.url'), '/').'/callback/wegas-session');
            $url = $response->successful() ? ($response->json('url') ?: null) : null;
        } catch (\Throwable $exception) {
            report($exception);
        }

        return view('site.wegas-sport', ['url' => $url]);
    }
}
