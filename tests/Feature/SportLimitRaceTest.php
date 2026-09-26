<?php

namespace Tests\Feature;

use Tests\TestCase;

class SportLimitRaceTest extends TestCase
{
    public function test_parallel_coupons_from_one_member_cannot_pass_the_total(): void
    {
        $username = $this->dbEnv('DB_USERNAME');
        $this->assertNotSame('', (string) $username);
        $this->assertNotSame('root', $username);

        $setup = $this->worker(['setup']);
        $this->assertSame(0, $setup['code'], $setup['output']);
        [$memberId, $oddId] = explode(' ', trim($setup['output']));
        $processes = [
            $this->openWorker(['place', $memberId, $oddId, 'race-a']),
            $this->openWorker(['place', $memberId, $oddId, 'race-b']),
        ];
        $output = '';
        foreach ($processes as &$process) {
            $this->finishWorker($process);
            $output .= $process['output'];
        }
        unset($process);

        $this->assertSame(1, substr_count($output, 'RESULT 0'), $output);
        $this->assertSame(1, substr_count($output, 'RESULT 2'), $output);

        $check = $this->concPdo();
        $coupons = $check->query('SELECT COUNT(*) FROM coupons')->fetchColumn();
        $this->assertSame('1', (string) $coupons, $output);
    }

    /**
     * @param  list<string>  $arguments
     * @return array{code: int, output: string}
     */
    private function worker(array $arguments): array
    {
        $process = $this->openWorker($arguments);

        return ['code' => $this->finishWorker($process), 'output' => $process['output']];
    }

    /**
     * @param  list<string>  $arguments
     * @return array{process: resource, pipes: array<int, resource>, output: string}
     */
    private function openWorker(array $arguments): array
    {
        $command = array_merge([PHP_BINARY, base_path('tests/Support/limit_worker.php')], $arguments);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $this->workerEnv());

        return ['process' => $process, 'pipes' => $pipes, 'output' => ''];
    }

    /**
     * @param  array{process: resource, pipes: array<int, resource>, output: string}  $process
     */
    private function finishWorker(array &$process): int
    {
        $process['output'] = stream_get_contents($process['pipes'][1]).stream_get_contents($process['pipes'][2]);
        fclose($process['pipes'][1]);
        fclose($process['pipes'][2]);

        return proc_close($process['process']);
    }

    /**
     * @return array<string, string>
     */
    private function workerEnv(): array
    {
        $env = [];
        foreach (array_merge($_SERVER, $_ENV) as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }
        foreach (['DB_USERNAME', 'DB_PASSWORD', 'DB_HOST', 'DB_PORT'] as $key) {
            $value = $this->dbEnv($key);
            if ($value !== null) {
                $env[$key] = $value;
            }
        }
        $env['DB_CONNECTION'] = 'mysql';
        $env['DB_DATABASE'] = 'wallet_conc_test';
        $env['DB_SOCKET'] = '';
        unset($env['DB_URL']);

        return $env;
    }

    private function dbEnv(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return null;
        }

        return (string) $value;
    }

    private function concPdo(): \PDO
    {
        $host = $this->dbEnv('DB_HOST') ?: '127.0.0.1';
        $port = $this->dbEnv('DB_PORT') ?: '3306';

        return new \PDO(
            'mysql:host='.$host.';port='.$port.';dbname=wallet_conc_test',
            (string) $this->dbEnv('DB_USERNAME'),
            (string) $this->dbEnv('DB_PASSWORD'),
        );
    }
}
