<?php

declare(strict_types=1);

// Copy for the package's own pagination control (resources/views/pagination.blade.php),
// which every paginating shipped surface renders through paginationView(). It replaces
// Livewire's built-in pagination view, whose markup carries a hardcoded English
// accessible name and a raw color palette no design token reaches.
return [
    // The accessible name of the <nav> landmark wrapping the page controls.
    'navigation' => 'Pagination',

    'previous' => 'Previous',
    'next' => 'Next',

    // The result counter. Every number travels as a placeholder, so a locale is free to
    // put them wherever its grammar wants them.
    'summary' => 'Showing :first to :last of :total results',
    // The same counter for a simple paginator, which asked for one page and one row
    // beyond it and therefore never counted a total. Naming no total is the honest form;
    // it is not a shorter phrasing of the line above.
    'summary_of_unknown_total' => 'Showing :first to :last',

    // What a filter change ANNOUNCES. A live-bound filter swaps the table and says nothing:
    // the count is in the document -- the summary above renders it -- but as a plain paragraph
    // outside any live region, so a reader has to travel back down and read to find out
    // whether the change produced three rows, two hundred or none (WCAG 4.1.3).
    //
    // Two forms, because a simple paginator counted nothing: it asked for one page and one row
    // beyond it, so it can only speak about the page it is on. Saying "12 results" there would
    // be a number about the wrong thing.
    'filtered' => '{0} No results.|{1} One result.|[2,*] :count results.',
    'filtered_of_unknown_total' => '{0} No results.|{1} One result on this page.|[2,*] :count results on this page.',

    // Strings only a screen reader announces. An untranslated accessible name is an
    // untranslated interface.
    'a11y' => [
        'goto_page' => 'Go to page :page',
        'current_page' => 'Page :page',
    ],
];
