<?php

namespace App\Support;

/** Aggregator arkasındaki gerçek oyun sağlayıcıları (görsel adresindeki kısa addan). */
class Vendors
{
    public const NAMES = [
        'casino-evolution' => 'Evolution',
        'casino-pragmatic' => 'Pragmatic Live',
        'casino-ezugi' => 'Ezugi',
        'casino-dream' => 'Dream Gaming',
        'casino-sa' => 'SA Gaming',
        'casino-playace' => 'PlayAce',
        'mini-aviator' => 'Aviator',
        'mini-spribe' => 'Spribe',
        'mini-inout' => 'InOut',
        'mini-bgaming' => 'BGaming',
        'mini-pragmatic' => 'Pragmatic Play',
        'mini-smartsoft' => 'SmartSoft',
        'slot-novomatic' => 'Novomatic',
        'slot-amatic' => 'Amatic',
        'slot-rubyplay' => 'Ruby Play',
        'slot-dreamtech' => 'DreamTech',
        'slot-popok' => 'PopOK',
        'slot-ka' => 'KA Gaming',
        'slot-jdb' => 'JDB',
        'slot-nolimitcity' => 'Nolimit City',
        'slot-evoplay' => 'Evoplay',
        'slot-fachai' => 'FaChai',
        'slot-wazdan' => 'Wazdan',
        'slot-playson' => 'Playson',
        'slot-wg' => 'WG',
        'slot-micro' => 'Microgaming',
        'slot-smartsoft' => 'SmartSoft',
        'slot-yellowbat' => 'Yellow Bat',
        'slot-amigo' => 'Amigo Gaming',
        'slot-popiplay' => 'PopiPlay',
        'slot-mascot' => 'Mascot',
        'slot-atg' => 'ATG',
        'pp' => 'Pragmatic Play', 'pg' => 'PG Soft', 'hacksaw' => 'Hacksaw', 'egt' => 'EGT', 'amusnet' => 'Amusnet',
        'bng' => 'Booongo', 'hab' => 'Habanero', 'jili' => 'JILI', 'cq9' => 'CQ9', '3oaks' => '3 Oaks',
        'tada' => 'TaDa', 'spribe' => 'Spribe', 'playstar' => 'PlayStar', 'xgaming' => 'XGaming',
        'atlasv' => 'AtlasV', 'solidicon' => 'Solidicon', 'beon' => 'BEON', 'tydo' => 'Tydo',
        'evolution' => 'Evolution', 'pragmaticplaylive' => 'Pragmatic Play Live', 'vivogaming' => 'Vivo Gaming', 'goldenrace' => 'GoldenRace', 'bgaming' => 'BGaming',
        'aviator' => 'Aviator', 'jetx' => 'JetX', 'rocketman' => 'Rocketman', 'spaceman' => 'Spaceman',
    ];

    public static function fromImage(?string $url): ?string
    {
        if ($url === null || ! preg_match('~/game_pic/([a-z0-9_-]+)/~i', $url, $m)) {
            return null;
        }

        return strtolower($m[1]);
    }

    public static function canonical(?string $slug): string
    {
        if ($slug === null) {
            return '';
        }

        return self::NAMES[$slug] ?? strtoupper($slug);
    }

    public static function name(?string $slug): ?string
    {
        if ($slug === null) {
            return null;
        }

        $key = 'vendors.'.$slug;
        $translated = __($key);

        return $translated === $key ? self::canonical($slug) : $translated;
    }

    public static function priority(?string $slug): int
    {
        $i = array_search($slug, array_keys(self::NAMES), true);

        return $i === false ? 999 : $i;
    }
}
