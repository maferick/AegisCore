<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\NvidiaNimClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * ai:nim-test — smoke test the NVIDIA NIM backend.
 *
 * Designed CI-safe: exits zero whether NIM is configured or not, so
 * pipelines never break on an unavailable model. Failure modes are
 * reported as warnings on stderr.
 *
 * Usage:
 *   php artisan ai:nim-test
 *   php artisan ai:nim-test --prompt="Summarize this test incident"
 *   php artisan ai:nim-test --json --prompt="Return {\"ok\": true}"
 *   php artisan ai:nim-test --model=z-ai/glm4.7
 */
class AiNimTestCommand extends Command
{
    protected $signature = 'ai:nim-test
        {--prompt=Summarize this test incident in one sentence. : prompt to send}
        {--model= : override primary model (defaults to config)}
        {--json : request JSON mode and pretty-print the response}
        {--max-tokens=400 : max_tokens override}
        {--temperature=0.2 : temperature override}';

    protected $description = 'Smoke test NVIDIA NIM. CI-safe — never exits non-zero on backend unavailability.';

    public function handle(NvidiaNimClient $nim): int
    {
        if (! $nim->isConfigured()) {
            $this->warn('nvidia_nim_not_configured — set NVIDIA_NIM_API_KEY in .env to enable. Exiting 0.');
            return self::SUCCESS;
        }

        $prompt = (string) $this->option('prompt');
        $model = $this->option('model');
        $jsonMode = (bool) $this->option('json');
        $maxTokens = (int) $this->option('max-tokens');
        $temperature = (float) $this->option('temperature');

        $messages = [
            ['role' => 'system', 'content' => $jsonMode
                ? 'You are a smoke test. Reply with a single JSON object containing the keys "ok" (bool) and "echo" (string).'
                : 'You are a smoke test. Reply with one sentence and nothing else.'],
            ['role' => 'user', 'content' => $prompt],
        ];

        $opts = [
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
        ];
        if (is_string($model) && $model !== '') {
            $opts['model'] = $model;
        }

        $start = microtime(true);

        try {
            if ($jsonMode) {
                $opts['json_mode'] = true;
                $resp = $nim->chatJsonOrNull($messages, $opts);
                if ($resp === null) {
                    $this->warn('nim_call_failed_or_unparseable — see logs (channel: nvidia_nim.*). Exiting 0.');
                    return self::SUCCESS;
                }
                $this->line('--- meta ---');
                $this->line(json_encode($resp['meta'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $this->line('--- data ---');
                $this->line(json_encode($resp['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return self::SUCCESS;
            }

            $resp = $nim->chat($messages, $opts);
        } catch (Throwable $e) {
            $this->warn('nim_call_threw: '.$e->getMessage().' — exiting 0 (CI-safe).');
            return self::SUCCESS;
        }

        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        $this->line(sprintf(
            'model_used=%s prompt_hash=%s attempts=%d fell_back=%s latency_ms=%d total_ms=%d',
            $resp['model_used'],
            $resp['prompt_hash'],
            $resp['attempts'],
            $resp['fell_back'] ? 'true' : 'false',
            $resp['latency_ms'],
            $elapsedMs,
        ));
        $usage = $resp['raw']['usage'] ?? null;
        if (is_array($usage)) {
            $this->line('usage='.json_encode($usage, JSON_UNESCAPED_SLASHES));
        }
        $this->line('--- content ---');
        $this->line($resp['content']);

        return self::SUCCESS;
    }
}
