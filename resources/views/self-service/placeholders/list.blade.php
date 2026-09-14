{{-- Lazy placeholder for the endpoint list.

     The floor is the point. A placeholder of fixed height swapped for content of variable height
     shifts everything below it, and the two were only ever the same height by accident: four
     skeleton rows stood 125px tall against the 210px this list takes when the account is empty --
     85px of downward jump, on the first screen every new tenant sees.

     It cost a real consumer its Core Web Vitals budget: CLS 0.148 against 0.1 for "good", on the
     one screen of twenty-three that was over. In the portal the list sits far enough down that
     nobody sees it; a host embedding the panel puts it where its own screen needs it, and there
     it lands above the fold.

     A floor cannot take out the jump for every account -- ten rows are taller than four skeleton
     ones whatever this says -- but it takes out the case that is both the worst and the certain
     one, and an arm holds it against the empty state's real height rather than against this
     number. --}}
<div class="wh-portal-list-placeholder min-h-52" role="status" aria-label="{{ __('webhooks::self-service.a11y.loading_endpoints') }}" wire:key="endpoint-list-placeholder">
    <x-wirekit::skeleton.table :rows="4" />
</div>
