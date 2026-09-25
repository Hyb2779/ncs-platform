<?php

namespace App\Services\Sport;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ApiFootballClient
{
    public function __construct(private readonly FootballBudget $budget) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    public function get(string $path, array $query = [], bool $critical = false): ?array
    {
        if (! $this->budget->allows($critical)) {
            return null;
        }

        $response = Http::withHeaders([
            'x-apisports-key' => (string) config('football.key'),
        ])->acceptJson()->timeout(30)->get(rtrim((string) config('football.url'), '/').$path, $query);

        $remaining = $response->header('x-ratelimit-requests-remaining');
        $this->budget->record(is_numeric($remaining) ? (int) $remaining : 0);

        $body = $response->json();

        if (! is_array($body) || $body['errors'] ?? false) {
            $errors = is_array($body) ? ($body['errors'] ?? []) : [];
            Log::warning('football.api.error', ['path' => $path, 'status' => $response->status(), 'errors' => $errors]);

            return null;
        }

        return $body;
    }
}
