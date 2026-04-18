{{--
    /admin/ship-class-categories/unclassified — review queue of hulls
    Spec 4 has seen in battle but has no classification for. Table body
    comes from the resource page's table() builder.
--}}
<x-filament-panels::page>
    <div class="fi-section-content-ctn">
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="fi-section-content p-6 text-sm text-gray-600 dark:text-gray-300">
                Hulls listed here have appeared on killmails inside tracked battle
                theaters but aren't in the ship-class mapping. Classifying one
                inserts a row into <code>ship_class_category_mapping</code> — the
                next Spec 4 run picks the value up immediately (no re-seed).
                Hulls that genuinely don't fit any role bucket should be
                classified as <strong>Other (explicit)</strong> so the extractor
                stops warning on them.
            </div>
        </div>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
