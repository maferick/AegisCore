<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IntelExportShareController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $artifact = DB::table('intel_export_artifacts')
            ->where('share_token', $token)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->first();
        if ($artifact === null) {
            abort(404);
        }

        $viewerBlocId = $this->resolveViewerBlocId();
        if ($viewerBlocId === null || (int) $artifact->viewer_bloc_id !== $viewerBlocId) {
            abort(403);
        }

        $download = $request->boolean('dl');
        $isMd = $artifact->format === 'markdown';
        $body = $isMd ? (string) ($artifact->body_md ?? '') : (string) ($artifact->body_json ?? '');
        $contentType = $isMd ? 'text/markdown; charset=utf-8' : 'application/json; charset=utf-8';
        $ext = $isMd ? 'md' : 'json';
        $headers = ['Content-Type' => $contentType];
        if ($download) {
            $safe = preg_replace('/[^A-Za-z0-9\-_]+/', '_', (string) $artifact->title);
            $headers['Content-Disposition'] = "attachment; filename=\"{$safe}.{$ext}\"";
        }
        return response($body, 200, $headers);
    }

    private function resolveViewerBlocId(): ?int
    {
        $user = Auth::user();
        if ($user === null) return null;
        $char = $user->characters()->first();
        if ($char === null || ! $char->alliance_id) return null;
        $blocId = DB::table('coalition_entity_labels')
            ->where('entity_type', 'alliance')
            ->where('entity_id', $char->alliance_id)
            ->where('is_active', 1)
            ->value('bloc_id');
        return $blocId ? (int) $blocId : null;
    }
}
