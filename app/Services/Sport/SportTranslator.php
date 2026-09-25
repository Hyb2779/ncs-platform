<?php

namespace App\Services\Sport;

use App\Models\SportCountry;
use App\Models\SportLeague;
use App\Models\SportTeam;
use App\Models\SportTranslation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SportTranslator
{
    public const LOCALES = ['tr', 'de', 'ar'];

    public function pendingCount(): int
    {
        return count($this->pending());
    }

    /**
     * @return list<array{type: string, id: int, name: string, missing: list<string>}>
     */
    public function pending(): array
    {
        $pending = [];
        foreach ($this->catalog() as $type => $rows) {
            $done = SportTranslation::query()
                ->where('entity_type', $type)
                ->whereIn('locale', self::LOCALES)
                ->get(['entity_id', 'locale'])
                ->groupBy('entity_id');

            foreach ($rows as $id => $name) {
                $have = $done->get($id, collect())->pluck('locale')->all();
                $missing = array_values(array_diff(self::LOCALES, $have));
                if ($missing !== []) {
                    $pending[] = ['type' => $type, 'id' => (int) $id, 'name' => (string) $name, 'missing' => $missing];
                }
            }
        }

        return $pending;
    }

    public function translate(): int
    {
        $key = config('services.anthropic.key');
        if (! is_string($key) || $key === '') {
            return 0;
        }

        $requests = 0;
        foreach (array_chunk($this->pending(), 50) as $chunk) {
            $requests++;
            $this->fill($key, $chunk);
        }

        return $requests;
    }

    /**
     * @param  list<array{type: string, id: int, name: string, missing: list<string>}>  $chunk
     */
    private function fill(string $key, array $chunk): void
    {
        $payload = array_map(fn (array $row) => [
            'id' => $row['type'].':'.$row['id'],
            'name' => $row['name'],
            'locales' => $row['missing'],
        ], $chunk);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
            ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-sonnet-4-6',
                'max_tokens' => 8000,
                'messages' => [[
                    'role' => 'user',
                    'content' => 'Translate these football club, national team, league, and country names into the requested locales. Use the official or common media name in that language. If none is known, transliterate. Return JSON only, no markdown: {"items":[{"id":"team:1","tr":"","de":"","ar":""}]}. Names: '.json_encode($payload, JSON_UNESCAPED_UNICODE),
                ]],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Sport translation request failed.');

            return;
        }

        if (! $response->successful()) {
            Log::warning('Sport translation request failed.', ['status' => $response->status()]);

            return;
        }

        $text = (string) data_get($response->json(), 'content.0.text', '');
        $decoded = json_decode($this->json($text), true);
        if (! is_array($decoded['items'] ?? null)) {
            Log::warning('Sport translation response was not usable.');

            return;
        }

        $byId = collect($chunk)->keyBy(fn (array $row) => $row['type'].':'.$row['id']);
        foreach ($decoded['items'] as $item) {
            $source = $byId->get($item['id'] ?? '');
            if ($source === null) {
                continue;
            }
            foreach ($source['missing'] as $locale) {
                $name = trim((string) ($item[$locale] ?? ''));
                if ($name === '') {
                    continue;
                }
                $existing = SportTranslation::query()->where([
                    'entity_type' => $source['type'],
                    'entity_id' => $source['id'],
                    'locale' => $locale,
                ])->first();
                if ($existing !== null) {
                    continue;
                }
                SportTranslation::query()->create([
                    'entity_type' => $source['type'],
                    'entity_id' => $source['id'],
                    'locale' => $locale,
                    'name' => $name,
                    'source' => 'auto',
                ]);
            }
        }
    }

    private function json(string $text): string
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return '';
        }

        return substr($text, $start, $end - $start + 1);
    }

    /**
     * @return array<string, \Illuminate\Support\Collection<int, string>>
     */
    private function catalog(): array
    {
        return [
            'team' => SportTeam::query()->pluck('name', 'id'),
            'league' => SportLeague::query()->pluck('name', 'id'),
            'country' => SportCountry::query()->pluck('name', 'id'),
        ];
    }
}
