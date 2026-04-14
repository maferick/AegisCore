<?php

use Filament\Facades\Filament;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        // Commands that live outside app/Console/Commands must be
        // registered explicitly — the framework only auto-discovers
        // the default path.
        \App\Reference\Console\CheckSdeVersionCommand::class,
        \App\Domains\UsersCharacters\Console\PollDonationsCommand::class,
        \App\Domains\UsersCharacters\Console\RecomputeDonorBenefitsCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // nginx terminates TLS and forwards to php-fpm over plain HTTP, setting
        // X-Forwarded-Proto=https. Without this trustProxies() call Laravel
        // reads $request->isSecure() as false and generates http:// asset URLs
        // on an https:// page — the browser blocks them as mixed content and
        // the Filament login form ends up with no CSS and no Livewire JS.
        //
        // Using `at: '*'` is the right call inside a Docker compose network:
        // the only thing that can reach php-fpm:9000 is the nginx container on
        // the internal bridge network, so there's no externally-reachable
        // untrusted proxy to worry about. If we ever expose php-fpm directly,
        // narrow this to the nginx service IP.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Unauthenticated hits to any `auth`-middleware'd route (including
        // /horizon, see HorizonServiceProvider) get redirected to the
        // Filament admin login instead of the stock Laravel `login` route
        // (which doesn't exist — we don't ship a standalone login page).
        // One login surface for the whole control plane.
        $middleware->redirectGuestsTo(fn () => Filament::getDefaultPanel()->getLoginUrl());
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
