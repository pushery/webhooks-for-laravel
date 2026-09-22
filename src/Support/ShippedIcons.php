<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Pushery\WireKit\Icons\IconSetPackages;
use Pushery\WireKit\WireKit;
use Throwable;

/**
 * Whether the icons the shipped screens draw will render on this installation.
 *
 * The screens name WireKit aliases, never a set: `globe`, `inbox`, `plus`. Which glyph an alias
 * becomes is the host's choice, made once in `wirekit.icons.preset` — heroicons by default, or
 * lucide, phosphor, tabler, or several of them stacked. So "is blade-heroicons installed" is the
 * right question only on a host that kept the default. It used to be the only one asked, and a
 * phosphor installation with every icon rendering was told its icon pair was half installed.
 *
 * The question asked here is the one a page answers when it renders: resolve each alias through
 * the host's own WireKit configuration, then ask Blade Icons whether it can draw the result. That
 * is how WireKit's own doctor checks its presets, and for the same reason: a host may ship the
 * glyphs itself under a preset's prefix, and then the package is absent while every icon resolves.
 * Asking Composer would report a problem there, and its remedy would make one — Blade Icons
 * refuses two sets that claim the same prefix.
 *
 * @internal
 */
final class ShippedIcons
{
    /**
     * The aliases the shipped views name literally.
     *
     * The row actions are not listed, because their aliases are configuration
     * (`webhooks.ui.row_action_icons`) and {@see self::aliases()} reads them from there. The test
     * beside this class holds the list against `resources/views` in both directions, so a new
     * screen that draws another icon cannot leave the check behind.
     */
    public const array LITERAL_ALIASES = ['dashboard', 'file-text', 'globe', 'inbox', 'plus', 'search', 'sort-desc'];

    /**
     * The container key Blade Icons registers its factory under. A string rather than a class
     * constant, because Blade Icons is optional here and its classes need not exist.
     */
    private const string FACTORY = 'BladeUI\Icons\Factory';

    /**
     * Every alias a shipped screen can draw on this installation.
     *
     * @return list<string>
     */
    public static function aliases(): array
    {
        $configured = Config::get('webhooks.ui.row_action_icons');

        // A host that emptied the whole key gets the label-only rows, so there is nothing to add.
        if (! is_array($configured)) {
            return self::LITERAL_ALIASES;
        }

        // A null entry is a host dropping that icon on purpose, so it is not something to draw.
        $rowActions = array_filter($configured, static fn (mixed $alias): bool => is_string($alias) && $alias !== '');

        return array_values(array_unique([...self::LITERAL_ALIASES, ...$rowActions]));
    }

    /**
     * The aliases that would draw WireKit's inert placeholder, each with the identifier it resolved
     * into, or an empty string when no configured preset defines it.
     *
     * Null when the question cannot be asked here. Without WireKit the shipped screens do not render
     * as shipped at all, and without Blade Icons nothing is drawn by anyone: every icon package goes
     * through it, a host's own set included, so its absence is not half of anything. That is a host
     * running without iconography, and the preflight has nothing to say to it.
     *
     * @return array<string, string>|null
     */
    public static function unresolved(): ?array
    {
        $container = Container::getInstance();

        if (! class_exists(WireKit::class) || ! $container->bound(self::FACTORY)) {
            return null;
        }

        $factory = $container->make(self::FACTORY);

        if (! is_object($factory) || ! method_exists($factory, 'svg')) {
            return null;
        }

        $unresolved = [];

        foreach (self::aliases() as $alias) {
            try {
                $identifier = WireKit::icon($alias);
            } catch (Throwable) {
                // In the console WireKit refuses an alias it cannot resolve, where a page would
                // degrade. Both end in the placeholder, which is all this needs to know.
                $identifier = '';
            }

            if ($identifier === '') {
                $unresolved[$alias] = '';

                continue;
            }

            try {
                $factory->svg($identifier);
            } catch (Throwable) {
                $unresolved[$alias] = $identifier;
            }
        }

        return $unresolved;
    }

    /**
     * What the preflight says about the shipped screens' icons: nothing, or one sentence per cause.
     *
     * Two causes, two sentences, because they have different remedies. A set that is not
     * registered is fixed with a `composer require`, or by registering a set of the host's own
     * under that prefix. An alias no preset defines is fixed in configuration, and naming a package
     * there would send the reader to install something that changes nothing.
     *
     * The input is an argument rather than a read, so every state is testable on a tree that has
     * neither Blade Icons nor any set, which is this one and every CI lane.
     *
     * @param  array<string, string>|null  $unresolved  the shape {@see self::unresolved()} returns
     * @return list<string>
     */
    public static function advisories(?array $unresolved): array
    {
        $unknown = [];
        $sets = [];

        foreach ($unresolved ?? [] as $alias => $identifier) {
            if ($identifier === '') {
                $unknown[] = $alias;

                continue;
            }

            $sets[Str::before($identifier, '-')] = IconSetPackages::forResolvedName($identifier);
        }

        $advisories = [];

        foreach ($sets as $prefix => $package) {
            $advisories[] = sprintf(
                'Your WireKit icon preset resolves the shipped screens\' icons into the \'%s\' set, and '
                .'no set with that prefix is registered with Blade Icons, so the screens draw WireKit\'s '
                .'inert placeholder where an icon belongs. They render — they are simply without '
                .'iconography. %s',
                $prefix,
                $package !== null
                    ? 'Install it: composer require '.$package.'.'
                    : 'Register a set under that prefix, or point WireKit at a preset whose set you have installed.',
            );
        }

        if ($unknown !== []) {
            $advisories[] = sprintf(
                'No configured WireKit icon preset defines %s, so the shipped screens draw WireKit\'s '
                .'inert placeholder there. Map %s in wirekit.icons.aliases, or name an alias your '
                .'preset defines in webhooks.ui.row_action_icons.',
                self::quotedList($unknown),
                count($unknown) === 1 ? 'it' : 'them',
            );
        }

        return $advisories;
    }

    /**
     * @param  non-empty-list<string>  $aliases
     */
    private static function quotedList(array $aliases): string
    {
        $quoted = array_map(static fn (string $alias): string => "'".$alias."'", $aliases);

        $last = array_pop($quoted);

        return $quoted === [] ? $last : implode(', ', $quoted).' or '.$last;
    }
}
