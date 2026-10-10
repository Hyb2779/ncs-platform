<?php

namespace App\Services\Sport;

use App\Models\SportFixture;
use App\Models\SportOdd;

/** Skordan kesinleşen Fenix marketleri. Korner, kart ve sıra bahisleri skorla kapanmaz. */
class OfferEvaluator
{
    public function evaluate(?SportOdd $odd, SportFixture $fixture): ?string
    {
        if ($odd === null) {
            return null;
        }

        $group = (string) $odd->group_name;
        $pick = (string) $odd->selection_name;
        $line = (string) $odd->handicap;
        $ft = $this->pair($fixture->ft_home, $fixture->ft_away);
        $ht = $this->pair($fixture->ht_home, $fixture->ht_away);

        return match ($group) {
            'Toplam Alt/Üst' => $this->total($pick, $ft, $line),
            'İlk Yarı Alt/Üst', 'İlk Yarı Toplam Alt/Üst' => $this->total($pick, $ht, $line),
            'İkinci Yarı Alt/Üst', 'İkinci Yarı Toplam Alt/Üst' => $this->total($pick, $this->rest($ft, $ht), $line),
            'Ev Sahibi Toplam Alt/Üst' => $this->total($pick, $ft === null ? null : [$ft[0], 0], $line, true),
            'Deplasman Toplam Alt/Üst' => $this->total($pick, $ft === null ? null : [0, $ft[1]], $line, true),
            'İlk Yarı Ev Sahibi Toplam Alt/Üst' => $this->total($pick, $ht === null ? null : [$ht[0], 0], $line, true),
            'İlk Yarı Deplasman Toplam Alt/Üst' => $this->total($pick, $ht === null ? null : [0, $ht[1]], $line, true),
            'Handikaplı' => $this->handicap($pick, $ft, $line),
            'İlk Yarı Handikaplı' => $this->handicap($pick, $ht, $line),
            'Maç Skoru' => $this->score($pick, $ft),
            'İlk Yarı Skoru' => $this->score($pick, $ht),
            'Beraberlikte İade' => $this->refund($pick, $ft),
            'İlk Yarı Beraberlikte İade' => $this->refund($pick, $ht),
            'Toplam Gol Tek/Çift' => $this->parity($pick, $ft),
            'İlk Yarı Tek/Çift' => $this->parity($pick, $ht),
            'İkinci Yarı Sonucu' => $this->winner($pick, $this->rest($ft, $ht)),
            'İlk Yarı / Maç Sonucu' => $this->halftimeFulltime($pick, $ht, $ft),
            'Karşılıklı Gol' => $this->both($pick, $ft),
            'İlk Yarı Karşılıklı Gol' => $this->both($pick, $ht),
            'İkinci Yarı Karşılıklı Gol' => $this->both($pick, $this->rest($ft, $ht)),
            'Her İki Yarıda Gol Olur' => $this->bothHalves($pick, $ht, $ft),
            'Tam Gol Sayısı' => $this->exactTotal($pick, $ft),
            default => null,
        };
    }

    /** @param  array{0:int,1:int}|null  $score */
    private function total(string $pick, ?array $score, string $line, bool $side = false): ?string
    {
        if ($score === null) {
            return null;
        }
        $goals = $side ? (string) max($score[0], $score[1]) : (string) ($score[0] + $score[1]);
        $cmp = bccomp($goals, $line, 2);
        if ($cmp === 0) {
            return 'void';
        }

        return match ($pick) {
            'Üst' => $cmp === 1 ? 'won' : 'lost',
            'Alt' => $cmp === -1 ? 'won' : 'lost',
            default => null,
        };
    }

    /** @param  array{0:int,1:int}|null  $score */
    private function handicap(string $pick, ?array $score, string $line): ?string
    {
        if ($score === null) {
            return null;
        }
        $cmp = bccomp(bcadd((string) $score[0], $line, 2), (string) $score[1], 2);

        return match ($pick) {
            '1' => $cmp === 0 ? 'void' : ($cmp === 1 ? 'won' : 'lost'),
            '2' => $cmp === 0 ? 'void' : ($cmp === -1 ? 'won' : 'lost'),
            'X' => $cmp === 0 ? 'won' : 'lost',
            default => null,
        };
    }

    /** @param  array{0:int,1:int}|null  $score */
    private function score(string $pick, ?array $score): ?string
    {
        if ($score === null || ! preg_match('/^(\d+)\s*[:\-]\s*(\d+)$/', $pick, $found)) {
            return null;
        }

        return ((int) $found[1] === $score[0] && (int) $found[2] === $score[1]) ? 'won' : 'lost';
    }

    /** @param  array{0:int,1:int}|null  $score */
    private function refund(string $pick, ?array $score): ?string
    {
        if ($score === null) {
            return null;
        }
        if ($score[0] === $score[1]) {
            return 'void';
        }

        return match ($pick) {
            '1' => $score[0] > $score[1] ? 'won' : 'lost',
            '2' => $score[0] < $score[1] ? 'won' : 'lost',
            default => null,
        };
    }

    /** @param  array{0:int,1:int}|null  $score */
    private function parity(string $pick, ?array $score): ?string
    {
        if ($score === null) {
            return null;
        }
        $odd = ($score[0] + $score[1]) % 2 === 1;

        return match ($pick) {
            'Tek' => $odd ? 'won' : 'lost',
            'Çift' => $odd ? 'lost' : 'won',
            default => null,
        };
    }

    /** @param  array{0:int,1:int}|null  $score */
    private function winner(string $pick, ?array $score): ?string
    {
        if ($score === null) {
            return null;
        }
        $actual = $score[0] > $score[1] ? '1' : ($score[0] < $score[1] ? '2' : 'X');

        return $pick === $actual ? 'won' : 'lost';
    }

    /**
     * @param  array{0:int,1:int}|null  $ht
     * @param  array{0:int,1:int}|null  $ft
     */
    private function halftimeFulltime(string $pick, ?array $ht, ?array $ft): ?string
    {
        if ($ht === null || $ft === null || ! preg_match('/^([12X])\/([12X])$/', $pick, $found)) {
            return null;
        }
        $half = $ht[0] > $ht[1] ? '1' : ($ht[0] < $ht[1] ? '2' : 'X');
        $full = $ft[0] > $ft[1] ? '1' : ($ft[0] < $ft[1] ? '2' : 'X');

        return ($found[1] === $half && $found[2] === $full) ? 'won' : 'lost';
    }

    /** @param  array{0:int,1:int}|null  $score */
    private function both(string $pick, ?array $score): ?string
    {
        if ($score === null) {
            return null;
        }
        $yes = $score[0] > 0 && $score[1] > 0;

        return match ($pick) {
            'Var', 'Evet' => $yes ? 'won' : 'lost',
            'Yok', 'Hayır' => $yes ? 'lost' : 'won',
            default => null,
        };
    }

    /**
     * @param  array{0:int,1:int}|null  $ht
     * @param  array{0:int,1:int}|null  $ft
     */
    private function bothHalves(string $pick, ?array $ht, ?array $ft): ?string
    {
        $second = $this->rest($ft, $ht);
        if ($ht === null || $second === null) {
            return null;
        }
        $yes = ($ht[0] + $ht[1]) > 0 && ($second[0] + $second[1]) > 0;

        return match ($pick) {
            'Evet', 'Var' => $yes ? 'won' : 'lost',
            'Hayır', 'Yok' => $yes ? 'lost' : 'won',
            default => null,
        };
    }

    /** @param  array{0:int,1:int}|null  $score */
    private function exactTotal(string $pick, ?array $score): ?string
    {
        if ($score === null || ! preg_match('/^(\d+)$/', $pick, $found)) {
            return null;
        }

        return ($score[0] + $score[1]) === (int) $found[1] ? 'won' : 'lost';
    }

    /** @return array{0:int,1:int}|null */
    private function pair(mixed $home, mixed $away): ?array
    {
        if ($home === null || $away === null || $home === '' || $away === '') {
            return null;
        }

        return [(int) $home, (int) $away];
    }

    /**
     * @param  array{0:int,1:int}|null  $ft
     * @param  array{0:int,1:int}|null  $ht
     * @return array{0:int,1:int}|null
     */
    private function rest(?array $ft, ?array $ht): ?array
    {
        if ($ft === null || $ht === null || $ht[0] > $ft[0] || $ht[1] > $ft[1]) {
            return null;
        }

        return [$ft[0] - $ht[0], $ft[1] - $ht[1]];
    }
}
