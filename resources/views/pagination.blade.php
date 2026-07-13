{{-- The package's own pagination control. Every paginating shipped component points
     Livewire's paginationView() at it, so the one control under the tables is styled
     with the same design tokens as the tables themselves and speaks the reader's
     language. Livewire's built-in view is deliberately not used: it paints a raw color
     palette (bg-white / text-gray-700 / dark: variants) that no token reaches, and its
     landmark carries a hardcoded English accessible name.

     The Livewire paging semantics are kept exactly: previousPage / nextPage / gotoPage
     are called with the paginator's own page name, so several paginators can live on
     one page without colliding. --}}
@php($pageName = $paginator->getPageName())
@php($control = 'inline-flex min-w-[2.25rem] items-center justify-center rounded-[var(--radius-wk-md)] border-[length:var(--border-wk-width)] px-[var(--padding-wk-x-sm)] py-[var(--padding-wk-y-sm)] transition-colors duration-[var(--transition-wk-duration)] focus:outline-none focus-visible:ring-[length:var(--ring-wk-width)] focus-visible:ring-[var(--color-wk-ring)]')
@php($enabled = $control.' cursor-pointer border-[color:var(--color-wk-border)] bg-[var(--color-wk-bg-elevated)] text-[color:var(--color-wk-text)] hover:bg-[var(--color-wk-bg-muted)]')
@php($current = $control.' border-[color:var(--color-wk-accent)] bg-[var(--color-wk-accent)] text-[color:var(--color-wk-accent-fg)] font-[number:var(--font-wk-heading-weight)]')
@php($inert = $control.' cursor-not-allowed border-[color:var(--color-wk-border)] bg-[var(--color-wk-bg-muted)] text-[color:var(--color-wk-text-muted)] opacity-[var(--opacity-wk-disabled)]')

@if ($paginator->hasPages())
    <nav
        role="navigation"
        aria-label="{{ __('webhooks::pagination.navigation') }}"
        class="wh-pagination flex flex-wrap items-center justify-between gap-[var(--gap-wk-sm)] font-[family-name:var(--font-wk-sans)] text-[length:var(--text-wk-sm)]"
    >
        <p class="text-[color:var(--color-wk-text-muted)]">
            {{ __('webhooks::pagination.summary', [
                'first' => $paginator->firstItem() ?? 0,
                'last' => $paginator->lastItem() ?? 0,
                'total' => $paginator->total(),
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
