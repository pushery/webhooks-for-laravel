{{-- Lazy placeholder for the endpoint list.

     The floor is the point. A placeholder of fixed height swapped for content of variable height
     shifts everything below it, and the two were only ever the same height by accident: four
     skeleton rows stood 125px tall against the 210px this list takes when the account is empty --
     85px of downward jump, on the first screen every new tenant sees.

     Such a jump counts against a host's Core Web Vitals: on an embedding screen it measured a CLS
     of 0.148, against 0.1 for "good". In the portal the list sits far enough down that
     nobody sees it; a host embedding the panel puts it where its own screen needs it, and there
     it lands above the fold.

     A floor cannot take out the jump for every account -- ten rows are taller than four skeleton
     ones whatever this says -- but it takes out the case that is both the worst and the certain
     one.

     224px, because the empty state's height depends on the WireKit release: from 2.64 it pads
     vertically with its side padding, and the empty list stands 226px tall; earlier releases
     draw it at 210px. This floor is within a few pixels of the first and within one spacing step
     of the second. --}}
<div class="wh-portal-list-placeholder min-h-56" role="status" aria-label="{{ __('webhooks::self-service.a11y.loading_endpoints') }}" wire:key="endpoint-list-placeholder">
    <x-wirekit::skeleton.table :rows="4" />
</div>
