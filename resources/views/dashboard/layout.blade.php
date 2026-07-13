{{-- Default full-page layout for the dashboard.

     WireKit's stylesheet (@wirekitStyles) carries the DESIGN TOKENS; the Tailwind
     utilities that consume them — in WireKit's components and in these views — are
     compiled by the host application. A host therefore points its Tailwind build at both
     packages' views (see the README's "Styling the UI" section: import this package's
     resources/css/webhooks.css and add WireKit's own source glob), or publishes and
     overrides this layout with the webhooks-dashboard-views tag.

     Dark mode: WireKit's dark tokens live behind a `.dark` class on the root element, so
     this layout puts it there — mirroring the reader's system preference by default, or
     pinned light/dark through webhooks.ui.theme. --}}
@php($theme = Webhooks\Support\UiTheme::class)
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if ($theme::forcesDark()) class="dark" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="color-scheme" content="{{ $theme::colorScheme() }}">
    <title>{{ $title ?? __('webhooks::dashboard.title') }}</title>
    @if ($theme::mirrorsSystem())
        {{-- Applied before the first paint, so a dark-mode reader never sees a white
             flash. Absent from the document when the theme is pinned, so a host under a
             strict CSP switches the inline script off by choosing light or dark. --}}
        <script>
            (function () {
                var query = window.matchMedia('(prefers-color-scheme: dark)');
                var apply = function (dark) { document.documentElement.classList.toggle('dark', dark); };
                apply(query.matches);
                query.addEventListener('change', function (event) { apply(event.matches); });
            })();
        </script>
    @endif
    @wirekitStyles
</head>
<body class="bg-[var(--color-wk-bg)] text-[color:var(--color-wk-text)] antialiased">
    {{-- WCAG 2.4.1 (Bypass Blocks): the first tab stop skips the window switcher and the
         tab strip straight into the content region, on every navigation. --}}
    <x-wirekit::skip-link target="wh-main" :label="__('webhooks::dashboard.a11y.skip_to_content')" />

    <main id="wh-main" tabindex="-1">
        {{ $slot }}
    </main>

    @wirekitScripts
</body>
</html>
