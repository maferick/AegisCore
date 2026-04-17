<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\KillmailResource\Pages;

use App\Domains\KillmailsBattleTheaters\Models\Killmail;
use App\Domains\KillmailsBattleTheaters\Services\KillmailViewData;
use App\Filament\Portal\Resources\KillmailResource;
use Filament\Resources\Pages\Page;

class ViewKillmail extends Page
{
    protected static string $resource = KillmailResource::class;

    protected string $view = 'filament.portal.pages.view-killmail';

    public $record;

    public function mount(int|string $record): void
    {
        $this->record = Killmail::with(['attackers', 'items'])->findOrFail($record);
    }

    public function getTitle(): string
    {
        $ship = $this->record->victim_ship_type_name ?? 'Kill';

        return "{$ship} — Kill #{$this->record->killmail_id}";
    }

    protected function getViewData(): array
    {
        return app(KillmailViewData::class)->build($this->record);
    }
}
