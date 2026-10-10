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
        abort_if(config('sport.own_book_enabled'), 404);

        $user = $request->user();
        abort_unless(wegas_sport_available($user), 404);

        $url = null;
        $live = $request->boolean('live');
        try {
            $body = ['user_id' => $user->id, 'name' => $user->username, 'lang' => $user->language->value];
            if ($live) {
                $body['path'] = (string) config('home.matches.live_path', 'canli-bahis');
            }
            $raw = json_encode($body);
            $ts = (string) time();
            $response = Http::withHeaders([
                'X-Bridge-Timestamp' => $ts,
                'X-Bridge-Signature' => hash_hmac('sha256', $ts.'.'.$raw, (string) config('services.ncs_bridge.secret')),
                'Accept' => 'application/json',
            ])->timeout(15)->withBody($raw, 'application/json')
                ->post(rtrim((string) config('services.ncs_bridge.url'), '/').'/callback/wegas-session');
            $url = $response->successful() ? ($response->json('url') ?: null) : null;
            if ($live && is_string($url) && $url !== '') {
                $url = $this->liveEntry($url);
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        return view('site.wegas-sport', ['url' => $url]);
    }

    /** Köprü kök adres döndürdüyse canlı sekmenin yolunu ekler. */
    private function liveEntry(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return $url;
        }

        $path = $parts['path'] ?? '/';
        if ($path !== '' && $path !== '/') {
            return $url;
        }

        $live = '/'.ltrim((string) config('home.matches.live_path', 'canli-bahis'), '/');
        $built = ($parts['scheme'] ?? 'https').'://'.$parts['host'];
        if (isset($parts['port'])) {
            $built .= ':'.$parts['port'];
        }
        $built .= $live;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $built .= '?'.$parts['query'];
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $built .= '#'.$parts['fragment'];
        }

        return $built;
    }
}
