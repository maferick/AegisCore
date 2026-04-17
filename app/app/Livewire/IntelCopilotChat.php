<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Domains\IntelCopilot\Services\BrokerError;
use App\Domains\IntelCopilot\Services\BrokerUnavailable;
use App\Domains\IntelCopilot\Services\IntelCopilotBroker;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Chat-style frontend for the intel_copilot broker.
 *
 * Stateful only within a single Livewire roundtrip — every submit pushes
 * the user turn onto ``$messages``, calls the broker, and pushes the
 * assistant turn on top. No persistence: refresh clears the transcript.
 * That's deliberate for the MVP — the plan + result is already logged
 * broker-side, and we don't want to spec a chat-history table until
 * someone actually asks for it.
 *
 * Messages carry the rendered payload so Blade doesn't have to know
 * broker internals:
 *
 *   role      'user' | 'assistant' | 'system'
 *   text      the visible paragraph
 *   rows      result rows (optional) — list of [label, value, meta]
 *   plan      QueryPlan dict (optional, for the "show plan" toggle)
 *   backend   e.g. 'opensearch', displayed as a badge
 *   took_ms   round-trip from broker, for the footer
 */
class IntelCopilotChat extends Component
{
    /** @var array<int, array<string, mixed>> */
    public array $messages = [];

    public string $draft = '';

    public bool $useLlm = true;

    public bool $busy = false;

    public function mount(): void
    {
        $this->messages[] = [
            'role' => 'system',
            'text' => 'Ask a question about combat data — "most used ship to kill freighters last 30 days", "how many kills in Delve this week". Heuristic parser handles common shapes for free; turn on LLM for the rest.',
        ];
    }

    public function ask(IntelCopilotBroker $broker): void
    {
        $question = trim($this->draft);
        if ($question === '') {
            return;
        }

        $this->busy = true;
        $this->draft = '';
        $this->messages[] = ['role' => 'user', 'text' => $question];

        try {
            $response = $broker->ask($question, useLlm: $this->useLlm);

            if (! $response->ok) {
                $this->messages[] = [
                    'role' => 'assistant',
                    'text' => "Broker rejected the request: {$response->error}",
                    'plan' => $response->plan,
                ];
            } else {
                $this->messages[] = $this->renderResult($response);
            }
        } catch (BrokerUnavailable $exc) {
            $this->messages[] = [
                'role' => 'assistant',
                'text' => 'Intel Copilot broker is not reachable. Is the Python service up?',
                'error' => $exc->getMessage(),
            ];
        } catch (BrokerError $exc) {
            $this->messages[] = [
                'role' => 'assistant',
                'text' => 'The broker returned an unexpected error. Check the `intel_copilot` container logs.',
                'error' => $exc->getMessage(),
            ];
        } finally {
            $this->busy = false;
        }
    }

    public function render(): View
    {
        return view('livewire.intel-copilot-chat');
    }

    /** @return array<string, mixed> */
    private function renderResult($response): array
    {
        $plan = $response->plan;
        $rows = $response->rows;
        $intent = $plan['intent'] ?? '';

        $text = match ($intent) {
            'count' => 'Count: '.number_format((int) ($response->total ?? 0)),
            'top_n', 'trend', 'list' => $rows === []
                ? 'No results.'
                : 'Top results:',
            'lookup' => $rows === [] ? 'No match found.' : 'Found:',
            default => 'Executed plan.',
        };

        return [
            'role' => 'assistant',
            'text' => $text,
            'rows' => $rows,
            'plan' => $plan,
            'parser' => $response->parser,
            'backend' => $response->backend,
            'total' => $response->total,
            'took_ms' => $response->tookMs,
        ];
    }
}
