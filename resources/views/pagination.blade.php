{{-- The package's own pagination control. Every paginating shipped component points
     Livewire's paginationView() at it, so the one control under the tables is styled
     with the same design tokens as the tables themselves and speaks the reader's
     language. Livewire's built-in view is deliberately not used: it paints a raw color
     palette (bg-white / text-gray-700 / dark: variants) that no token reaches, and its
     landmark carries a hardcoded English accessible name.

     The Livewire paging semantics are kept exactly: previousPage / nextPage / gotoPage
     are called with the paginator's own page name, so several paginators can live on
     one page without colliding.

     ONE view carries BOTH paginator types, and that is deliberate. Livewire resolves the
     two independently -- paginationView() for a LengthAwarePaginator, paginationSimpleView()
     for the simple one it gets from simplePaginate() -- so a component that overrides only
     the first silently keeps Livewire's built-in view for the second. A second view of our
     own would fix that and then drift from this one; the two things a simple paginator
     cannot answer are read defensively below instead. --}}
@php($pageName = $paginator->getPageName())
{{-- A simple paginator asked for one page and one row beyond it, so it knows neither the
     total nor the list of pages, and Paginator::render() passes no $elements at all. Both
     absences are normal here, not a caller mistake. --}}
@php($elements = $elements ?? [])
@php($total = $paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator ? $paginator->total() : null)
@php($control = 'inline-flex min-w-[2.25rem] items-center justify-center rounded-[var(--radius-wk-md)] border-[length:var(--border-wk-width)] px-[var(--padding-wk-x-sm)] py-[var(--padding-wk-y-sm)] transition-colors duration-[var(--transition-wk-duration)] focus:outline-none focus-visible:ring-[length:var(--ring-wk-width)] focus-visible:ring-[var(--color-wk-ring)]')
@php($enabled = $control.' cursor-pointer border-[color:var(--color-wk-border)] bg-[var(--color-wk-bg-elevated)] text-[color:var(--color-wk-text)] hover:bg-[var(--color-wk-bg-muted)]')
@php($current = $control.' border-[color:var(--color-wk-accent)] bg-[var(--color-wk-accent)] text-[color:var(--color-wk-accent-fg)] font-[number:var(--font-wk-heading-weight)]')
{{-- NO opacity on the inert state, and that is the whole of this line's history. It used to
     carry `opacity-[var(--opacity-wk-disabled)]` on top of the muted pair, and opacity applies
     to the ELEMENT: text and element background are composited against the page together, so
     6.26:1 became 2.15:1 light and 2.59:1 dark. "Previous" on the first page was close to
     unreadable, and the ellipsis between page numbers vanished.

     It was also a second answer to a question already answered three times over. The border,
     the cursor and the absent hover all say "not available"; the opacity only said it again,
     and it was the one saying it that broke the contrast. An opacity composites against
     whatever is behind the element, so its result is not a property of the design system at
     all — a token would at least be computable. --}}
@php($inert = $control.' cursor-not-allowed border-[color:var(--color-wk-border)] bg-[var(--color-wk-bg-muted)] text-[color:var(--color-wk-text-muted)]')

@if ($paginator->hasPages())
    <nav
        role="navigation"
        {{-- A caller may name its own landmark. A page that hosts two paginators otherwise
             offers two navigation landmarks with the identical accessible name, and a
             screen-reader user jumping to one of them is guessing. --}}
        aria-label="{{ $landmarkLabel ?? __('webhooks::pagination.navigation') }}"
        class="wh-pagination flex flex-wrap items-center justify-between gap-[var(--gap-wk-sm)] font-[family-name:var(--font-wk-sans)] text-[length:var(--text-wk-sm)]"
    >
        <p class="text-[color:var(--color-wk-text-muted)]">
            {{ $total === null
                ? __('webhooks::pagination.summary_of_unknown_total', [
                    'first' => $paginator->firstItem() ?? 0,
                    'last' => $paginator->lastItem() ?? 0,
                ])
                : __('webhooks::pagination.summary', [
                    'first' => $paginator->firstItem() ?? 0,
                    'last' => $paginator->lastItem() ?? 0,
                    'total' => $total,
                ]) }}
        </p>

        <div class="flex flex-wrap items-center gap-[var(--gap-wk-sm)]">
            @if ($paginator->onFirstPage())
                <span class="{{ $inert }}" aria-hidden="true">{{ __('webhooks::pagination.previous') }}</span>
            @else
                <button type="button" wire:click="previousPage('{{ $pageName }}')" wire:loading.attr="disabled" class="{{ $enabled }}">
                    {{ __('webhooks::pagination.previous') }}
                </button>
            @endif

            {{-- The framework hands each element as either a separator string or a
                 page => url map; a numeric page is a real control, a separator is not. --}}
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="{{ $inert }}" aria-hidden="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page === $paginator->currentPage())
                            <span
                                class="{{ $current }}"
                                aria-current="page"
                                aria-label="{{ __('webhooks::pagination.a11y.current_page', ['page' => $page]) }}"
                            >{{ $page }}</span>
                        @else
                            <button
                                type="button"
                                wire:click="gotoPage({{ $page }}, '{{ $pageName }}')"
                                wire:loading.attr="disabled"
                                class="{{ $enabled }}"
                                aria-label="{{ __('webhooks::pagination.a11y.goto_page', ['page' => $page]) }}"
                            >{{ $page }}</button>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <button type="button" wire:click="nextPage('{{ $pageName }}')" wire:loading.attr="disabled" class="{{ $enabled }}">
                    {{ __('webhooks::pagination.next') }}
                </button>
            @else
                <span class="{{ $inert }}" aria-hidden="true">{{ __('webhooks::pagination.next') }}</span>
            @endif
        </div>
    </nav>
@endif
