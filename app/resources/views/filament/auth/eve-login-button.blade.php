{{--
    "Log in with EVE" button, rendered under the default Filament login form
    via the `panels::auth.login.form.after` render hook (see
    AdminPanelProvider).

    The email+password form stays live so operator-seeded accounts
    (`make filament-user`) still work. Most humans will click this button.

    No Tailwind build pipeline runs here — the classes are all stock
    Filament utility classes that ship with the framework's compiled CSS.
--}}
<div class="mt-6 flex flex-col items-stretch gap-3">
    <div class="relative flex items-center">
        <div class="flex-grow border-t border-gray-200 dark:border-white/10"></div>
        <span class="mx-3 text-xs uppercase tracking-wider text-gray-400 dark:text-gray-500">
            or
        </span>
        <div class="flex-grow border-t border-gray-200 dark:border-white/10"></div>
    </div>

    <a
        href="{{ route('auth.eve.redirect') }}"
        class="fi-btn fi-btn-color-primary fi-btn-size-md fi-btn-outlined inline-flex items-center justify-center gap-2 rounded-lg border px-4 py-2.5 text-sm font-semibold transition
               border-primary-600 text-primary-600 hover:bg-primary-50
               dark:border-primary-500 dark:text-primary-400 dark:hover:bg-primary-500/10"
    >
        {{-- EVE Online's public SSO badge is CCP trademark; keep text-only. --}}
        <span>Log in with EVE Online</span>
    </a>
</div>
