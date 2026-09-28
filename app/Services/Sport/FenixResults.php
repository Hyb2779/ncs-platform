<?php

namespace App\Services\Sport;

use Illuminate\Support\Facades\Http;

/**
 * Fenix resultbot: biten maçın skorunu kazanan marketlerden çıkarır (Sonuçlar sayfası).
 * Final: "Correct Score". İlk yarı: "1st Half - Correct Score", yoksa ev/deplasman 1. yarı toplam gol (2529/2531).
 */
class FenixResults
{
    public const WON = 3;

    /** @return array{ft: array{0:int,1:int}, ht: ?array{0:int,1:int}}|null */
    public function fetch(string $eventId): ?array
    {
        $json = Http::timeout(15)->acceptJson()
            ->get((string) config('fenix.resultbot_url'), ['eventid' => $eventId])
            ->json();

        if (! is_array($json) || empty($json['results']) || empty($json['is_full_results_update'])) {
            return null;
        }

        return self::parse((array) $json['results']);
    }

    public static function parse(array $markets): ?array
    {
        $won = [];
        foreach ($markets as $m) {
            $names = [];
            foreach ((array) ($m['odds'] ?? []) as $o) {
                if ((int) ($o['status'] ?? 0) === self::WON) {
                    $names[] = trim((string) ($o['metadata']['name'] ?? ''));
                }
            }
            $won[] = ['id' => (int) ($m['id'] ?? 0), 'name' => strtolower(trim((string) ($m['name'] ?? ''))), 'won' => $names];
        }

        $ft = self::correct($won, 'correct score');
        if ($ft === null) {
            return null;
        }

        $ht = self::correct($won, '1st half - correct score');
        if ($ht === null) {
            $h = self::goals($won, 2529);
            $a = self::goals($won, 2531);
            $ht = ($h !== null && $a !== null) ? [$h, $a] : null;
        }
        if ($ht !== null && ($ht[0] > $ft[0] || $ht[1] > $ft[1])) {
            $ht = null; // tutarsız ilk yarı skoru yazılmaz
        }

        return ['ft' => $ft, 'ht' => $ht];
    }

    private static function correct(array $won, string $name): ?array
    {
        foreach ($won as $m) {
            if ($m['name'] === $name && count($m['won']) === 1
                && preg_match('/^(\d+)\s*[:\-]\s*(\d+)$/', $m['won'][0], $x)) {
                return [(int) $x[1], (int) $x[2]];
            }
        }

        return null;
    }

    private static function goals(array $won, int $id): ?int
    {
        foreach ($won as $m) {
            if ($m['id'] !== $id || $m['won'] === []) {
                continue;
            }
            $g = 0;
            foreach ($m['won'] as $n) {
                if (preg_match('/^over\s+(\d+)\.5$/i', $n, $x)) {
                    $g = max($g, (int) $x[1] + 1);
                }
            }

            return $g;
        }

        return null;
    }
}
