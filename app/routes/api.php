<?php

declare(strict_types=1);

use App\Http\Controllers\EveLogIngestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Token-authenticated endpoints. Auth is enforced inside each
| controller method (Bearer token → eve_log_upload_clients lookup).
|
| Phase 3 — EVE log ingest.
|
*/

Route::post('/eve-log-ingest/chunk', [EveLogIngestController::class, 'chunk'])
    ->name('api.eve-log-ingest.chunk');
