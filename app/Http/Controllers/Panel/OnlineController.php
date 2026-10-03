<?php

namespace App\Http\Controllers\Panel;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Middleware\TrackPresence;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/** Online kullanicilar (son 5 dk). Owner + superadmin, kendi agaci; bayi 404. */
class OnlineController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $request->user();
        abort_unless(in_array($actor->role, [UserRole::Owner, UserRole::Superadmin], true), 404);

        $ids = User::query()->subtreeOf($actor)->whereKeyNot($actor->id)->pluck('id')->all();
        $presence = $ids === [] ? [] : array_filter(Cache::many(array_map(fn ($id) => 'presence:'.$id, $ids)));
        $limit = now()->getTimestamp() - TrackPresence::TTL;
        $online = [];
        foreach ($presence as $key => $info) {
            if (is_array($info) && ($info['at'] ?? 0) >= $limit) {
                $online[(int) substr($key, 9)] = $info;
            }
        }
        arsort($online);
        uasort($online, fn ($a, $b) => $b['at'] <=> $a['at']);

        $users = User::query()->whereIn('id', array_keys($online) ?: [0])->get()->keyBy('id');
        $parents = User::query()->whereIn('id', $users->pluck('parent_id')->filter()->unique()->all() ?: [0])->pluck('username', 'id');
        $zone = $actor->timezone ?: 'Europe/Istanbul';

        $rows = [];
        foreach ($online as $id => $info) {
            $u = $users->get($id);
            if ($u === null) {
                continue;
            }
            $rows[] = [
                'user' => $u->username,
                'role' => __('panel.roles.'.$u->role->value),
                'parent' => $parents[$u->parent_id] ?? '—',
                'area' => __('panel.online_area_'.$info['area']),
                'seen' => Carbon::createFromTimestamp($info['at'])->timezone($zone)->format('H:i'),
                'ip' => $info['ip'] ?? '—',
                'device' => self::device($info['ua'] ?? null),
            ];
        }

        return view('panel.online.index', ['rows' => $rows]);
    }

    public static function device(?string $ua): string
    {
        if ($ua === null || $ua === '') {
            return '—';
        }
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => '?',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => '?',
        };

        return $os.' · '.$browser;
    }
}
