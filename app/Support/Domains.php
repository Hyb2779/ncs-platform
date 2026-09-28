<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;

/** Site (üye) ve panel (owner/süperadmin/bayi) alan adı ayrımı. PANEL_DOMAIN boşsa ayrım kapalı. */
class Domains
{
    public static function enabled(): bool
    {
        return filled(config('domains.panel'));
    }

    public static function isPanelHost(Request $request): bool
    {
        return self::enabled() && strtolower($request->getHost()) === strtolower((string) config('domains.panel'));
    }

    /** Bu kapıdan bu kullanıcı girebilir mi? */
    public static function gateAllows(Request $request, User $user): bool
    {
        if (! self::enabled()) {
            return true;
        }

        return self::isPanelHost($request) ? $user->role !== UserRole::Uye : $user->role === UserRole::Uye;
    }

    public static function panelUrl(string $path = '/'): string
    {
        return 'https://'.config('domains.panel').'/'.ltrim($path, '/');
    }

    public static function siteUrl(string $path = '/'): string
    {
        return 'https://'.(config('domains.site') ?: request()->getHost()).'/'.ltrim($path, '/');
    }
}
