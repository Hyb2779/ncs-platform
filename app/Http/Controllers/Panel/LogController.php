<?php

namespace App\Http\Controllers\Panel;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Illuminate\View\View;

/**
 * Faz 5: Islem logu (yonetim islemleri) ve Giris logu. Owner + superadmin.
 * Kok owner her seyi gorur; digerleri sadece kendi agacindaki kisilerin yaptigi / kisilere yapilan kayitlari.
 */
class LogController extends Controller
{
    private const NOISY = ['wallet.posted'];

    private const LOGIN_ACTIONS = ['auth.login', 'auth.login_failed'];

    public function index(Request $request): View
    {
        return $this->render($request, 'actions');
    }

    public function logins(Request $request): View
    {
        return $this->render($request, 'logins');
    }

    private function render(Request $request, string $kind): View
    {
        $actor = $request->user();
        abort_unless(in_array($actor->role, [UserRole::Owner, UserRole::Superadmin], true), 404);
        $zone = $actor->timezone ?: 'Europe/Istanbul';
        $morph = (new User())->getMorphClass();

        $query = ActivityLog::query()->orderByDesc('id');
        if ($kind === 'logins') {
            $query->whereIn('action', self::LOGIN_ACTIONS);
        } else {
            $query->where('action', 'not like', 'auth.%');
            $action = (string) $request->query('action', '');
            $action !== '' ? $query->where('action', $action) : $query->whereNotIn('action', self::NOISY);
        }

        if (! $actor->isRootOwner()) {
            $ids = User::query()->subtreeOf($actor)->select('id');
            $query->where(fn ($q) => $q->whereIn('actor_id', $ids)
                ->orWhere(fn ($t) => $t->where('target_type', $morph)->whereIn('target_id', $ids)));
        }
        if ($request->filled('q')) {
            $uids = User::query()->subtreeOf($actor)->where('username', 'like', '%'.$request->string('q').'%')->pluck('id');
            $query->where(fn ($q) => $q->whereIn('actor_id', $uids)
                ->orWhere(fn ($t) => $t->where('target_type', $morph)->whereIn('target_id', $uids)));
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', Carbon::parse($request->query('from'), $zone)->startOfDay()->utc());
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', Carbon::parse($request->query('to'), $zone)->endOfDay()->utc());
        }

        $logs = $query->paginate(50)->withQueryString();
        $userIds = $logs->getCollection()->flatMap(function ($log) use ($morph) {
            $p = is_array($log->payload) ? $log->payload : (json_decode((string) $log->payload, true) ?: []);

            return [$log->actor_id, $log->target_type === $morph ? $log->target_id : null, $p['from_user_id'] ?? null, $p['to_user_id'] ?? null];
        })->filter()->unique();
        $names = User::query()->whereIn('id', $userIds->all() ?: [0])->pluck('username', 'id');

        $rows = $logs->getCollection()->map(function (ActivityLog $log) use ($names, $zone, $morph, $kind): array {
            $payload = is_array($log->payload) ? $log->payload : (json_decode((string) $log->payload, true) ?: []);
            $actorName = $names[$log->actor_id] ?? (isset($payload['username']) ? $payload['username'].' (?)' : __('panel.empty_value'));
            $failed = $log->action === 'auth.login_failed';

            return [
                'when' => Carbon::parse($log->getRawOriginal('created_at'), 'UTC')->timezone($zone)->format('d.m.Y H:i:s'),
                'actor' => $actorName,
                'action' => $kind === 'logins'
                    ? new \Illuminate\Support\HtmlString('<span class="'.($failed ? 'text-red-700' : 'text-emerald-800').'">'.e($this->label($log->action)).'</span>')
                    : $this->label($log->action),
                'target' => $log->target_type === $morph ? ($names[$log->target_id] ?? '#'.$log->target_id) : __('panel.empty_value'),
                'ip' => $log->ip ?: __('panel.empty_value'),
                'device' => $this->device($payload['ua'] ?? null),
                'detail' => $this->detail($payload, (string) $log->action, $names),
            ];
        })->all();

        $actions = $kind === 'actions'
            ? ActivityLog::query()->where('action', 'not like', 'auth.%')->distinct()->orderBy('action')->pluck('action')
                ->mapWithKeys(fn ($a) => [$a => $this->label($a)])->all()
            : [];

        return view('panel.logs.index', [
            'kind' => $kind,
            'logs' => $logs,
            'rows' => $rows,
            'actions' => $actions,
            'heading' => $kind === 'logins' ? __('panel.logs_logins') : __('panel.logs_actions'),
        ]);
    }

    private function label(string $action): string
    {
        $key = 'panel.log_action_'.str_replace('.', '_', $action);

        return Lang::has($key) ? __($key) : $action;
    }

    private function detail(array $payload, string $action = '', $names = []): string
    {
        unset($payload['ua'], $payload['out_id'], $payload['in_id']);
        $who = fn ($id) => $id === null ? '?' : ($names[$id] ?? '#'.$id);
        $role = fn ($r) => is_string($r) && Lang::has('panel.roles.'.$r) ? __('panel.roles.'.$r) : (string) $r;
        $money = fn ($amount, $currency) => number_format((float) $amount, 2, ',', '.').' '.$currency;

        switch ($action) {
            case 'wallet.transferred':
                return $who($payload['from_user_id'] ?? null).' → '.$who($payload['to_user_id'] ?? null).' · '.$money($payload['amount'] ?? 0, $payload['currency'] ?? '');
            case 'user.created':
                return $role($payload['role'] ?? '').' · '.($payload['username'] ?? '');
            case 'user.role_migrated':
                return $role($payload['from'] ?? '').' → '.$role($payload['to'] ?? '').(isset($payload['reason']) ? ' · '.$payload['reason'] : '');
        }

        $parts = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $parts[] = $key.': '.mb_strimwidth(implode(',', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $value)), 0, 40, '…');
            } elseif ($value !== null && $value !== '') {
                $parts[] = $key.': '.(is_bool($value) ? ($value ? '1' : '0') : (string) $value);
            }
        }

        return $parts === [] ? __('panel.empty_value') : mb_strimwidth(implode(' · ', $parts), 0, 140, '…');
    }

    /** Kaba cihaz bilgisi: isletim sistemi + tarayici. */
    private function device(?string $ua): string
    {
        if ($ua === null || $ua === '') {
            return __('panel.empty_value');
        }
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => '?',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => '?',
        };

        return $os.' · '.$browser;
    }
}
