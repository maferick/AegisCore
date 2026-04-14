{{--
    /admin/container-status — full docker stack view.

    The header widget (ContainerStatusWidget) renders the summary
    cards above this automatically. Below it, we render a simple
    Filament-styled table from the DockerStatusService snapshot.

    We skip Filament's Table builder here because the data is a
    plain list of value objects rather than an Eloquent query —
    the builder's sort/filter/paginate features don't carry their
    weight for ~20 rows that refresh every five seconds. A vanilla
    table backed by Filament's section component renders in the
    same visual style as the other admin pages with much less
    ceremony.
--}}
<x-filament-panels::page>
    @php
        /** @var \App\System\DockerSnapshot $snapshot */
        $snapshot = $this->snapshot();
    @endphp

    {{-- wire:poll refreshes the Livewire component every 5s so the
         container table reflects state changes without F5. The
         service's internal cache (5s happy-path, 10s error-path)
         keeps this from hammering the proxy even with multiple
         admins on the page. --}}
    <div wire:poll.5s>
    <x-filament::section>
        <x-slot name="heading">Container list</x-slot>
        <x-slot name="description">
            One row per container known to Docker. Status column combines lifecycle
            state (running / exited / restarting) with the healthcheck suffix when
            one is declared on the image.
        </x-slot>

        @if (! $snapshot->configured)
            <div class="text-sm text-gray-500 dark:text-gray-400">
                Docker monitoring is not configured. Set <code class="font-mono">DOCKER_API_HOST</code>
                in your <code class="font-mono">.env</code> and restart php-fpm / scheduler / horizon
                to enable this page. See <code class="font-mono">infra/docker-compose.yml</code> for the
                bundled <code class="font-mono">docker_socket_proxy</code> sidecar.
            </div>
        @elseif ($snapshot->isError())
            <div class="text-sm text-danger-600 dark:text-danger-400">
                <div class="font-medium">Docker API unreachable</div>
                <div class="mt-0.5 font-mono text-xs">{{ $snapshot->error }}</div>
            </div>
        @elseif (count($snapshot->containers) === 0)
            <div class="text-sm text-gray-500 dark:text-gray-400">
                No containers reported by the Docker API.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2 font-medium">Name</th>
                            <th class="px-3 py-2 font-medium">State</th>
                            <th class="px-3 py-2 font-medium">Uptime</th>
                            <th class="px-3 py-2 font-medium">Image</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($snapshot->containers as $container)
                            <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="px-3 py-2 font-mono text-xs font-medium text-gray-950 dark:text-white">
                                    {{ $container->name }}
                                </td>
                                <td class="px-3 py-2">
                                    <x-filament::badge :color="$this->levelColor($container)">
                                        {{ $this->stateLabel($container) }}
                                    </x-filament::badge>
                                </td>
                                <td class="px-3 py-2 text-gray-600 dark:text-gray-300">
                                    {{ $this->formatUptime($container) }}
                                </td>
                                <td class="px-3 py-2 font-mono text-xs text-gray-600 dark:text-gray-300">
                                    {{ $container->image }}
                                </td>
                                <td class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $container->statusLine ?: '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
    </div>
</x-filament-panels::page>
