<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Render\Ai;

use Voyager\Wayfinder\Blocks\Locks;
use Voyager\Wayfinder\Manifest\IdFormat;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Scanners\Builders;
use Voyager\Wayfinder\Scanners\ThemeGates;

/**
 * The key facts column of a compact line: the few facts per kind that answer
 * most questions, in plain words. Everything else stays in the section tier.
 *
 * Reads facts by name, so the order a scanner wrote them in never changes the
 * output. A kind this class does not know falls back to its scalar facts.
 * Counts come from the item's stats and print as bands (see Band), so the
 * line stays the same while content grows within a band.
 */
final class Facts
{
    /** `used in` names at most this many containers before `+N`. */
    public const USED_IN_SHOWN = 5;

    /** Post types WordPress registers itself. Every other one is custom. */
    public const BUILTIN_POST_TYPES = ['attachment', 'page', 'post'];

    /** Longest value an option or unknown fact shows before it is cut. */
    private const VALUE_CHARS = 80;

    /**
     * @param bool                    $fieldLists Name a post type's fields. False collapses them to a count.
     * @param array<string, true>|null $listed    Ids the reader can see. `used in` names these and published
     *                                            content first, and counts the rest (folded items) in `+N`.
     * @param bool                    $unread     Name a binding's unread settings. A reader that lists every
     *                                            binding of a source names them on the first one only.
     */
    public static function describe(Item $item, bool $fieldLists = true, ?array $listed = null, bool $unread = true): string
    {
        $facts = $item->facts;

        $parts = match ($item->kind) {
            'post_type'     => self::postType($item, $fieldLists),
            'taxonomy'      => self::taxonomy($item),
            'field'         => self::field($facts),
            'option'        => [self::value($facts['value'] ?? null)],
            'core'          => [self::text($facts['version'] ?? null)],
            'plugin'        => [self::text($facts['role'] ?? null), self::text($facts['version'] ?? null)],
            'template'      => [...self::access($item), ...self::template($facts)],
            'template_part' => ['area ' . self::text($facts['area'] ?? null), ...self::access($item), ...self::template($facts), self::usedIn($item, $listed)],
            'pattern'       => self::pattern($item, $listed),
            'navigation'    => [Band::of(self::stat($item, 'item_count')) . ' items', self::cannotOpen($item), self::usedIn($item, $listed)],
            'binding'       => self::binding($item, $listed, $unread),
            'menu_location' => [...self::menuLocation($item), self::cannotOpen($item)],
            'sidebar'       => [Band::of(self::stat($item, 'widget_count')) . ' widgets', self::cannotOpen($item)],
            'options_page'  => self::optionsPage($item, $fieldLists),
            'gates'         => self::gates($item),
            'builder_template' => self::builderTemplate($facts),
            'rest_route'    => self::restRoute($facts),
            'head_filter'   => self::headFilter($item),
            default         => self::scalars($facts),
        };

        $missing = self::idList($facts['missing_refs'] ?? null);
        if ($missing !== []) {
            $parts[] = 'missing ' . implode(', ', $missing);
        }

        // A class the editor never shows (anything but a style variation) is worth a word.
        if (($facts['hidden_classes'] ?? false) === true) {
            $parts[] = 'carries hidden CSS classes';
        }

        return implode('; ', array_values(array_filter($parts, static fn (string $part): bool => trim($part) !== '')));
    }

    /**
     * Published count, or uploaded for media.
     */
    public static function postCount(Item $item): int
    {
        return self::statusCount($item, $item->id === 'pt:attachment' ? 'inherit' : 'publish');
    }

    public static function termCount(Item $item): int
    {
        return self::stat($item, 'terms');
    }

    /**
     * Visible in wp-admin with no public URL.
     */
    public static function isAdminOnly(Item $item): bool
    {
        return ($item->facts['public'] ?? null) === false && ($item->facts['show_ui'] ?? null) === true;
    }

    public static function isCustomPostType(Item $item): bool
    {
        return $item->kind === 'post_type' && !in_array(substr($item->id, 3), self::BUILTIN_POST_TYPES, true);
    }

    /**
     * Field keys of a post type, from its `field:<type>.<key>` refs, sorted.
     *
     * @return list<string>
     */
    public static function fieldKeys(Item $item): array
    {
        $prefix = 'field:' . substr($item->id, 3) . '.';
        $keys = [];

        foreach ($item->refs as $ref) {
            if (str_starts_with($ref, $prefix)) {
                $keys[] = substr($ref, strlen($prefix));
            }
        }

        return $keys;
    }

    public static function usageCount(Item $item): int
    {
        return self::stat($item, 'usage_count');
    }

    /**
     * What uses an item, for `used in`: listed layout containers by id, up to
     * five, then `+N` for the other layout containers (all of them when none
     * is listed: `N folded items`), then the content that uses it as a band,
     * `content 1-5`. Layout containers are the inverse of other items' refs,
     * so they only change with the structure. Content is never named, so a
     * new page using a pattern leaves the line alone until its band moves.
     * Empty when nothing uses the item.
     *
     * @param array<string, true>|null $listed Ids the reader can see. Null names every container.
     * @return list<string>
     */
    public static function usedInParts(Item $item, ?array $listed = null): array
    {
        $usedIn = self::idList($item->stats['used_in'] ?? null);
        $total = is_int($item->stats['usage_count'] ?? null) ? $item->stats['usage_count'] : count($usedIn);

        $layout = array_values(array_filter($usedIn, [IdFormat::class, 'isValid']));
        $content = max(0, $total - count($layout));

        $named = $listed === null
            ? $layout
            : array_values(array_filter($layout, static fn (string $id): bool => isset($listed[$id])));
        $shown = array_slice($named, 0, self::USED_IN_SHOWN);
        $more = count($layout) - count($shown);

        $parts = [];

        if ($shown !== []) {
            $parts[] = implode(', ', $shown) . ($more > 0 ? ' +' . $more : '');
        } elseif ($more > 0) {
            $parts[] = $more . ' folded item' . ($more === 1 ? '' : 's');
        }

        if ($content > 0) {
            $parts[] = 'content ' . Band::of($content);
        }

        return $parts;
    }

    /**
     * Who can open a block template, part or synced pattern, then what its
     * layout allows, kept apart: `editor cannot open: needs
     * edit_theme_options`, then `not locked at its edit path`, `content only
     * inside a template (WordPress 7 default)` or the locked blocks. The
     * role is named as able to open it only when there is a lock to state.
     *
     * @return list<string>
     */
    public static function access(Item $item): array
    {
        $what = self::lockParts($item);
        $client = $item->stats['client'] ?? null;
        $who = self::cannotOpen($item);

        if ($who === '' && $what !== [] && is_array($client) && ($client['can_open'] ?? false) === true && is_string($client['role'] ?? null)) {
            $who = $client['role'] . ' can open it';
        }

        return array_values(array_filter([$who, ...$what], static fn (string $part): bool => $part !== ''));
    }

    /**
     * `editor cannot open: needs edit_theme_options`, from `stats.client`,
     * or empty when the client role can open the item or nobody knows.
     */
    public static function cannotOpen(Item $item): string
    {
        $client = $item->stats['client'] ?? null;

        if (!is_array($client) || ($client['can_open'] ?? null) !== false || !is_string($client['needs'] ?? null)) {
            return '';
        }

        return (is_string($client['role'] ?? null) ? $client['role'] : 'client') . ' cannot open: needs ' . $client['needs'];
    }

    /**
     * What a block container's layout allows, from its `lock` and
     * `locked_blocks` facts: the locked blocks grouped by lock (see
     * lockedGroups()), `not locked at its edit path` when only a template
     * locks it, and `content only inside a template (WordPress 7 default)`.
     * Empty when nothing is locked anywhere, or the item has no lock fact.
     *
     * @return list<string>
     */
    public static function lockParts(Item $item): array
    {
        $lock = $item->facts['lock'] ?? null;

        if (!is_array($lock)) {
            return [];
        }

        $parts = [];
        $locked = is_int($lock['locked'] ?? null) ? $lock['locked'] : 0;
        $inTemplate = $lock['in_template'] ?? null;

        if ($locked > 0) {
            $groups = self::lockedGroups($item->facts['locked_blocks'] ?? null, $locked);
            $parts[] = $groups !== ''
                ? 'locked blocks ' . $groups
                : $locked . ' locked block' . ($locked === 1 ? '' : 's') . ', strictest ' . self::text($lock['effective'] ?? null) . ' (' . self::text($lock['src'] ?? null) . ')';
        } elseif ($inTemplate !== null) {
            $parts[] = 'not locked at its edit path';
        }

        if ($inTemplate === 'content_only') {
            $parts[] = 'content only inside a template (WordPress 7 default): there only its text can change';
        }

        return $parts;
    }

    /**
     * Locked blocks grouped by what their lock allows, in the order the
     * first of each group appears: `Plan name, Plan price (content only,
     * template_lock: text can change, cannot be moved, removed or restyled)
     * and License notice (all, block: text can change, cannot be moved or
     * removed)`. A block is named by its List View name, else `<type> at
     * <path>`. Each group names at most USED_IN_SHOWN blocks, then `+N`, and
     * blocks past the fact's cap are counted at the end. Empty when the list
     * is missing or empty.
     */
    public static function lockedGroups(mixed $blocks, int $locked = 0): string
    {
        if (!is_array($blocks) || $blocks === []) {
            return '';
        }

        /** @var array<string, array{names: list<string>, words: string}> $groups */
        $groups = [];
        $listed = 0;

        foreach ($blocks as $block) {
            if (!is_array($block) || !is_string($block['effective'] ?? null)) {
                continue;
            }

            $listed++;
            $effective = $block['effective'];
            $src = self::text($block['src'] ?? null);
            $text = ($block['text_editable'] ?? false) === true;
            $key = $effective . '|' . $src . '|' . ($text ? '1' : '0');
            $name = is_string($block['name'] ?? null) && $block['name'] !== ''
                ? $block['name']
                : preg_replace('/^core\//', '', self::text($block['block'] ?? null)) . ' at ' . self::text($block['path'] ?? null);

            $groups[$key] ??= ['names' => [], 'words' => str_replace('_', ' ', $effective) . ', ' . $src . ': ' . Locks::effect($effective, $text)];
            $groups[$key]['names'][] = $name;
        }

        $phrases = [];

        foreach ($groups as $group) {
            $more = count($group['names']) - self::USED_IN_SHOWN;
            $phrases[] = implode(', ', array_slice($group['names'], 0, self::USED_IN_SHOWN)) . ($more > 0 ? ' +' . $more : '') . ' (' . $group['words'] . ')';
        }

        $rest = $locked - $listed;

        return implode(' and ', $phrases) . ($rest > 0 ? ' and ' . $rest . ' more' : '');
    }

    /**
     * @return list<string>
     */
    private static function postType(Item $item, bool $fieldLists): array
    {
        $facts = $item->facts;

        $parts = [
            Band::of(self::postCount($item)) . ($item->id === 'pt:attachment' ? ' uploaded' : ' published'),
        ];

        foreach (['draft', 'private'] as $status) {
            $count = self::statusCount($item, $status);

            if ($count > 0) {
                $parts[] = Band::of($count) . ' ' . $status;
            }
        }

        foreach (self::builderDocs($item) as $builder => $count) {
            $parts[] = Band::of($count) . ' ' . Builders::bundled()->label($builder) . ' documents';
        }

        if (self::isAdminOnly($item)) {
            $parts[] = 'admin-only, no public URL';
        }

        if (($facts['show_in_rest'] ?? null) === false) {
            $parts[] = 'not in REST';
        }

        $archive = self::archivePath($item);
        if ($archive !== null) {
            $parts[] = 'archive ' . $archive;
        }

        $fields = self::fieldKeys($item);
        if ($fields !== []) {
            $parts[] = $fieldLists ? 'fields ' . implode(', ', $fields) : self::count(count($fields), 'field');
        }

        $parts[] = self::definedIn($facts);

        return $parts;
    }

    /**
     * The archive URL path: has_archive when it is a slug, else the rewrite
     * slug, else the post type slug.
     */
    private static function archivePath(Item $item): ?string
    {
        $hasArchive = $item->facts['has_archive'] ?? false;

        if ($hasArchive === false || $hasArchive === '' || ($item->facts['public'] ?? false) !== true) {
            return null;
        }

        $rewrite = $item->facts['rewrite_slug'] ?? null;
        $slug = match (true) {
            is_string($hasArchive)                => $hasArchive,
            is_string($rewrite) && $rewrite !== '' => $rewrite,
            default                                => substr($item->id, 3),
        };

        return '/' . trim($slug, '/') . '/';
    }

    /**
     * @return list<string>
     */
    private static function taxonomy(Item $item): array
    {
        $facts = $item->facts;
        $parts = [Band::of(self::termCount($item)) . ' terms'];

        if (($facts['hierarchical'] ?? false) === true) {
            $parts[] = 'hierarchical';
        }

        $types = is_array($facts['object_types'] ?? null) ? array_map('strval', $facts['object_types']) : [];
        if ($types !== []) {
            $parts[] = 'on ' . implode(', ', $types);
        }

        $parts[] = self::definedIn($facts);

        return $parts;
    }

    /**
     * @param array<string, mixed> $facts
     * @return list<string>
     */
    private static function field(array $facts): array
    {
        $source = self::text($facts['source'] ?? null);
        $parts = [self::text($facts['type'] ?? null)];

        if ($source === 'acf') {
            $group = self::text($facts['group'] ?? null);
            $parts[] = 'ACF' . ($group !== '' ? ' group ' . $group : '');

            // A repeater's or group's sub fields, a flexible content field's layouts.
            $subFields = self::idList($facts['sub_fields'] ?? null);
            $layouts = self::idList($facts['layouts'] ?? null);

            if ($subFields !== []) {
                $parts[] = 'sub fields ' . implode(', ', $subFields);
            }

            if ($layouts !== []) {
                $parts[] = 'layouts ' . implode(', ', $layouts);
            }
        } elseif ($source === 'registered_meta') {
            $parts[] = 'registered meta';
        } elseif ($source !== '') {
            $parts[] = $source;
        }

        if (($facts['required'] ?? false) === true) {
            $parts[] = 'required';
        }

        if (($facts['in_rest'] ?? null) === false) {
            $parts[] = 'not in REST';
        }

        return $parts;
    }

    /**
     * Templates and parts: the file they come from (relative to wp-content,
     * so it names the theme), whether it is a Site Editor copy, and for a
     * classic PHP file what it reads and the paths it links to.
     *
     * @param array<string, mixed> $facts
     * @return list<string>
     */
    private static function template(array $facts): array
    {
        $source = self::text($facts['source'] ?? null);
        $file = self::text($facts['file'] ?? null);
        $fileTheme = self::text($facts['file_theme'] ?? null);
        $overrides = self::text($facts['overrides'] ?? null);

        $parts = [match (true) {
            $source === 'theme' && $file !== ''      => 'file ' . $file,
            $source === 'theme' && $fileTheme !== '' => 'file from ' . $fileTheme,
            $source === 'custom'                     => 'made in the Site Editor',
            default                                  => $source,
        }];

        if ($overrides !== '') {
            $parts[] = 'overrides ' . $overrides;
        }

        if (($facts['user_modified'] ?? false) === true) {
            $parts[] = 'edited in the Site Editor';
        }

        // A classic PHP file's reads and hard-coded links, and for a template what it prints of the post.
        $reads = self::idList($facts['reads'] ?? null);
        $links = self::idList($facts['links'] ?? null);
        $shows = is_array($facts['shows_post'] ?? null) ? self::idList($facts['shows_post']) : null;

        if ($shows !== null) {
            $missing = array_values(array_diff(['title', 'content'], $shows));

            if ($missing !== []) {
                $parts[] = 'never shows the post\'s own ' . implode(' or ', $missing);
            }
        }

        if ($reads !== []) {
            $parts[] = 'reads ' . implode(', ', $reads);
        }

        if ($links !== []) {
            $parts[] = 'hard codes links ' . implode(', ', $links) . ' in the file (no field or setting changes them)';
        }

        return $parts;
    }

    /**
     * A synced pattern, who can open it and what its lock allows; or a
     * registered one, its theme file and where the options it reads are
     * edited (see readScreens()). Then `used in`.
     *
     * @param array<string, true>|null $listed
     * @return list<string>
     */
    private static function pattern(Item $item, ?array $listed): array
    {
        $file = self::text($item->facts['file'] ?? null);

        return [
            ($item->facts['synced'] ?? false) === true ? 'synced' : 'registered',
            $file !== '' ? 'file ' . $file : '',
            ...self::access($item),
            ...self::readScreens($item),
            self::usedIn($item, $listed),
        ];
    }

    /**
     * What a registered pattern's file reads, grouped by where it is edited,
     * in read order: `reads option:voyager_site_phone (Phone Number), edited
     * at options-general.php?page=voyager-blocks&tab=site-data`, `reads
     * option:a (A) and option:b (B, empty now), all edited at <path>`, and
     * last the reads with no screen, `reads option:acme_promo, edit screen
     * not traced`. `empty now` comes from `stats.empty_reads`; no value is
     * ever printed. Empty when the file reads nothing or was not traced.
     *
     * @return list<string>
     */
    public static function readScreens(Item $item): array
    {
        $screens = [];

        foreach (is_array($item->facts['read_screens'] ?? null) ? $item->facts['read_screens'] : [] as $screen) {
            if (is_array($screen) && is_string($screen['read'] ?? null) && is_string($screen['edit'] ?? null)) {
                $screens[$screen['read']] = $screen;
            }
        }

        $empty = array_fill_keys(self::idList($item->stats['empty_reads'] ?? null), true);

        /** @var array<string, list<string>> $groups Edit path ('' when not traced) => reads. */
        $groups = [];

        foreach (self::idList($item->facts['reads'] ?? null) as $read) {
            $label = $screens[$read]['label'] ?? null;
            $notes = array_filter([is_string($label) ? Compact::clean($label) : '', isset($empty[$read]) ? 'empty now' : '']);
            $groups[$screens[$read]['edit'] ?? ''][] = $read . ($notes !== [] ? ' (' . implode(', ', $notes) . ')' : '');
        }

        $parts = [];

        foreach ($groups as $edit => $reads) {
            if ($edit !== '') {
                $parts[] = 'reads ' . self::andList($reads) . ', ' . (count($reads) === 1 ? 'edited at ' : 'all edited at ') . $edit;
            }
        }

        if (isset($groups[''])) {
            $parts[] = 'reads ' . self::andList($groups['']) . ', edit screen' . (count($groups['']) === 1 ? '' : 's') . ' not traced';
        }

        return $parts;
    }

    /**
     * `a`, `a and b`, `a, b and c`.
     *
     * @param non-empty-list<string> $names
     */
    public static function andList(array $names): string
    {
        $last = array_pop($names);

        return $names === [] ? $last : implode(', ', $names) . ' and ' . $last;
    }

    /**
     * A classic menu location: the menu assigned to it and its item band.
     *
     * @return list<string>
     */
    private static function menuLocation(Item $item): array
    {
        $menu = self::text($item->facts['menu'] ?? null);

        return $menu === ''
            ? ['no menu assigned']
            : ['menu ' . $menu, Band::of(self::stat($item, 'item_count')) . ' items'];
    }

    /**
     * An ACF options page: its fields (a count when field lists collapse)
     * and the field groups located on it.
     *
     * @return list<string>
     */
    private static function optionsPage(Item $item, bool $fieldLists): array
    {
        $fields = self::idList($item->facts['fields'] ?? null);
        $groups = self::idList($item->facts['field_groups'] ?? null);

        return [
            $fields === [] ? '' : ($fieldLists ? 'fields ' . implode(', ', $fields) : self::count(count($fields), 'field')),
            $groups === [] ? '' : 'ACF ' . (count($groups) === 1 ? 'group ' : 'groups ') . implode(', ', $groups),
        ];
    }

    /**
     * @param array<string, true>|null $listed
     * @return list<string>
     */
    private static function binding(Item $item, ?array $listed, bool $unread = true): array
    {
        $bound = is_array($item->facts['bound'] ?? null) ? array_map('strval', $item->facts['bound']) : [];

        return [
            $bound !== [] ? 'binds ' . implode(', ', $bound) : '',
            self::readsPart($item),
            self::cannotOpen($item),
            self::definedIn($item->facts),
            self::usedIn($item, $listed),
            $unread ? self::unreadPart($item) : '',
        ];
    }

    /**
     * `the same settings also hold vwf_hours (Hours), which nothing reads,
     * so it shows nowhere`, from a binding's `unread_settings`, or empty.
     */
    public static function unreadPart(Item $item): string
    {
        $unread = $item->facts['unread_settings'] ?? null;

        if (!is_array($unread) || $unread === []) {
            return '';
        }

        $named = [];

        foreach ($unread as $setting) {
            if (is_array($setting) && is_string($setting['option'] ?? null)) {
                $label = $setting['label'] ?? null;
                $named[] = $setting['option'] . (is_string($label) && $label !== '' ? ' (' . Compact::clean($label) . ')' : '');
            }
        }

        if ($named === []) {
            return '';
        }

        return sprintf(
            'the same settings also hold %s, which no block or theme file reads, so %s nowhere',
            implode(', ', $named),
            count($named) === 1 ? 'it shows' : 'they show'
        );
    }

    /**
     * What a binding reads, `option:<name>` and `meta:<key>`, from its
     * `reads` fact. Empty when it reads nothing or nobody could tell.
     *
     * @return list<string>
     */
    public static function reads(Item $item): array
    {
        return self::idList($item->facts['reads'] ?? null);
    }

    /**
     * `reads option:vwf_phone`, or empty.
     */
    public static function readsPart(Item $item): string
    {
        $reads = self::reads($item);

        return $reads === [] ? '' : 'reads ' . implode(', ', $reads);
    }

    /**
     * `defined in mu-plugins/acme.php`, `defined in core`, or empty when
     * the file is not known.
     *
     * @param array<string, mixed> $facts
     */
    private static function definedIn(array $facts): string
    {
        $file = self::text($facts['defined_in'] ?? null);

        return $file !== '' ? 'defined in ' . $file : '';
    }

    /**
     * `used in a, b +2, content 1-5`, or `unused`. See usedInParts().
     *
     * @param array<string, true>|null $listed
     */
    private static function usedIn(Item $item, ?array $listed = null): string
    {
        $parts = self::usedInParts($item, $listed);

        return $parts === [] ? 'unused' : 'used in ' . implode(', ', $parts);
    }

    /**
     * An integer stat, 0 when the item has none.
     */
    private static function stat(Item $item, string $name): int
    {
        $value = $item->stats[$name] ?? 0;

        return is_int($value) ? $value : 0;
    }

    private static function statusCount(Item $item, string $status): int
    {
        $counts = $item->stats['counts'] ?? [];

        return is_array($counts) && is_int($counts[$status] ?? null) ? $counts[$status] : 0;
    }

    /**
     * The theme gates as the brief names them: each control turned off in
     * plain words, then whether appearanceTools is on, how many per-block
     * overrides there are, and which post types limit their blocks. Empty
     * when nothing is turned off or limited.
     *
     * @return list<string>
     */
    public static function gateWords(Item $item): array
    {
        $gates = self::gateMap($item);
        $overrides = self::blockOverrides($item);
        $limited = self::limitedBlocks($item);

        if ($gates === [] && $overrides === [] && $limited === []) {
            return [];
        }

        $words = [];
        foreach (array_keys($gates) as $path) {
            if ($path !== 'appearanceTools') {
                $words[] = ThemeGates::WORDS[$path] ?? $path . ' off';
            }
        }

        $words[] = 'appearanceTools ' . (isset($gates['appearanceTools']) ? 'off' : 'on');

        if ($overrides !== []) {
            $words[] = count($overrides) . ' block override' . (count($overrides) === 1 ? '' : 's');
        }

        if ($limited !== []) {
            $words[] = 'allowed blocks limited on ' . implode(', ', array_keys($limited));
        }

        return $words;
    }

    /**
     * Index line for `gates:theme`: appearanceTools, every control turned
     * off with its origin, per-block overrides and limited post types.
     *
     * @return list<string>
     */
    private static function gates(Item $item): array
    {
        $gates = self::gateMap($item);
        $settings = is_array($item->facts['settings'] ?? null) ? $item->facts['settings'] : [];

        $parts = ['appearanceTools ' . (($settings['appearanceTools'] ?? false) === true ? 'on' : 'off')
            . (isset($gates['appearanceTools']) ? ' (' . $gates['appearanceTools'] . ')' : '')];

        $off = [];
        foreach ($gates as $path => $origin) {
            if ($path !== 'appearanceTools') {
                $off[] = (ThemeGates::WORDS[$path] ?? $path . ' off') . ' (' . $origin . ')';
            }
        }
        $parts[] = $off === [] && $gates === [] ? 'no controls turned off' : implode(', ', $off);

        $overrides = array_map(
            static fn (array $override): string => $override['block'] . ': ' . $override['setting'] . ': ' . ($override['value'] ? 'on' : 'off'),
            self::blockOverrides($item)
        );
        if ($overrides !== []) {
            $parts[] = 'block overrides ' . implode(', ', $overrides);
        }

        $limited = self::limitedBlocks($item);
        if ($limited !== []) {
            $parts[] = 'allowed blocks ' . implode(', ', array_map(
                static fn (string $type, int $count): string => $type . ' ' . $count,
                array_keys($limited),
                $limited
            ));
        }

        return $parts;
    }

    /**
     * @return array<string, string> Path => origin, for the settings turned off.
     */
    private static function gateMap(Item $item): array
    {
        $gates = $item->facts['gates'] ?? null;
        $map = [];

        if (is_array($gates)) {
            foreach ($gates as $path => $origin) {
                $map[(string) $path] = is_string($origin) ? $origin : 'default';
            }
        }

        return $map;
    }

    /**
     * @return list<array{block: string, setting: string, value: bool}>
     */
    private static function blockOverrides(Item $item): array
    {
        $overrides = $item->facts['block_overrides'] ?? null;
        $list = [];

        if (is_array($overrides)) {
            foreach ($overrides as $override) {
                if (is_array($override) && is_string($override['block'] ?? null) && is_string($override['setting'] ?? null)) {
                    $list[] = ['block' => $override['block'], 'setting' => $override['setting'], 'value' => ($override['value'] ?? false) === true];
                }
            }
        }

        return $list;
    }

    /**
     * @return array<string, int> Post type => blocks allowed, for the post types not allowed every block.
     */
    private static function limitedBlocks(Item $item): array
    {
        $allowed = $item->facts['allowed_blocks'] ?? null;
        $limited = [];

        if (is_array($allowed)) {
            foreach ($allowed as $type => $count) {
                if (is_int($count)) {
                    $limited[(string) $type] = $count;
                }
            }
        }

        return $limited;
    }

    /**
     * Published documents of a post type each page builder owns, by builder
     * slug, from the `builder_docs` stat. Empty when no builder owns any.
     *
     * @return array<string, int>
     */
    public static function builderDocs(Item $item): array
    {
        $docs = $item->stats['builder_docs'] ?? null;

        return is_array($docs) ? array_filter($docs, 'is_int') : [];
    }

    /**
     * `Elementor template; type header; content not read`.
     *
     * @param array<string, mixed> $facts
     * @return list<string>
     */
    private static function builderTemplate(array $facts): array
    {
        return [
            Builders::bundled()->label(self::text($facts['builder'] ?? null)) . ' template',
            is_string($facts['template_type'] ?? null) ? 'type ' . $facts['template_type'] : '',
            'content not read',
        ];
    }

    /**
     * A REST route: per endpoint, its methods, handler and file, who may call
     * it, and for a traced handler what it does, recipients by name only:
     * `POST acme_contact_handle in themes/acme/inc/contact.php; public;
     * records an Orbit lead; mails the address in constant ACME_CONTACT_NOTIFY
     * (declared in themes/acme/inc/contact.php) through
     * plugin:postmark-approved-wordpress-plugin; fires acme_contact_submit
     * (nothing listens)`.
     *
     * @param array<string, mixed> $facts
     * @return list<string>
     */
    private static function restRoute(array $facts): array
    {
        $parts = [];
        $endpoints = is_array($facts['endpoints'] ?? null) ? $facts['endpoints'] : [];

        foreach ($endpoints as $endpoint) {
            if (!is_array($endpoint)) {
                continue;
            }

            $methods = implode(', ', self::idList($endpoint['methods'] ?? null));
            $file = self::text($endpoint['file'] ?? null);
            $parts[] = trim($methods . ' ' . self::text($endpoint['handler'] ?? null)) . ($file !== '' ? ' in ' . $file : '');
            $parts[] = match ($endpoint['permission'] ?? null) {
                'public'  => 'public',
                'missing' => 'public (no permission callback)',
                default   => 'checks permission',
            };

            if (($endpoint['traced'] ?? false) !== true) {
                $parts[] = 'what it does is not traced (plugin code is not read)';
                continue;
            }

            foreach (self::idList($endpoint['records'] ?? null) as $record) {
                $parts[] = 'records ' . $record;
            }

            $mails = is_array($endpoint['mails'] ?? null) ? $endpoint['mails'] : [];
            $through = self::text($endpoint['mail_through'] ?? null);

            if ($mails === []) {
                $parts[] = 'sends no mail in the code it follows';
            }

            foreach ($mails as $mail) {
                $parts[] = 'mails ' . self::recipient(is_array($mail) ? $mail : []) . ($through !== '' ? ' through ' . $through : '');
            }

            foreach (self::idList($endpoint['creates_posts'] ?? null) as $type) {
                $parts[] = 'creates ' . $type . ' posts';
            }

            if (($endpoint['redirects'] ?? false) === true) {
                $parts[] = 'redirects';
            }

            foreach (is_array($endpoint['fires'] ?? null) ? $endpoint['fires'] : [] as $fire) {
                if (!is_array($fire)) {
                    continue;
                }

                $listeners = self::idList($fire['listeners'] ?? null);
                $parts[] = 'fires ' . self::text($fire['action'] ?? null) . ($listeners === [] ? ' (nothing listens)' : ' (listened to in ' . implode(', ', array_unique($listeners)) . ')');
            }
        }

        return $parts;
    }

    /**
     * Who a traced mail goes to, by name. Never an address.
     *
     * @param array<array-key, mixed> $mail
     */
    private static function recipient(array $mail): string
    {
        $name = self::text($mail['name'] ?? null);
        $declared = self::text($mail['declared_in'] ?? null);

        return match ($mail['to'] ?? null) {
            'constant'        => 'the address in constant ' . $name . ($declared !== '' ? ' (declared in ' . $declared . ')' : ''),
            'option'          => 'the address in option ' . $name,
            'filter'          => 'the address the filter ' . $name . ' returns',
            'address in code' => 'an address typed into the code',
            default           => 'an address not traced',
        };
    }

    /**
     * A head filter: what it changes and where, its callback and file, and
     * for a traced callback, whether it returns fixed text and what it reads:
     * `changes og:title on route:front-page (is_front_page()); acme_og_title
     * in themes/acme/functions.php; returns fixed text typed into that file;
     * applied by plugin:seo-by-rank-math`.
     *
     * @return list<string>
     */
    private static function headFilter(Item $item): array
    {
        $facts = $item->facts;
        $routes = array_values(array_filter($item->refs, static fn (string $ref): bool => str_starts_with($ref, 'route:')));
        $conditions = self::idList($facts['conditions'] ?? null);
        $where = $routes !== [] ? ' on ' . implode(', ', $routes) : '';

        if ($conditions !== []) {
            $where .= ' (' . implode(', ', $conditions) . ')';
        }

        $file = self::text($facts['file'] ?? null);
        $parts = [
            'changes ' . self::text($facts['changes'] ?? null) . $where,
            self::text($facts['callback'] ?? null) . ($file !== '' ? ' in ' . $file : ''),
        ];

        if (($facts['traced'] ?? false) !== true) {
            $parts[] = 'what it returns is not traced (plugin code is not read)';
        } else {
            if (($facts['returns_fixed_text'] ?? false) === true) {
                $parts[] = 'returns fixed text typed into that file';
            }

            $reads = self::idList($facts['reads'] ?? null);

            if ($reads !== []) {
                $parts[] = 'reads ' . implode(', ', $reads);
            }
        }

        $appliedBy = self::text($facts['applied_by'] ?? null);

        if ($appliedBy !== '') {
            $parts[] = 'applied by plugin:' . $appliedBy;
        }

        return $parts;
    }

    /**
     * Scalar facts as `key value`, sorted by key. For kinds added later.
     *
     * @param array<string, mixed> $facts
     * @return list<string>
     */
    private static function scalars(array $facts): array
    {
        ksort($facts, SORT_STRING);
        $parts = [];

        foreach ($facts as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $parts[] = $key . ' ' . self::value($value);
            }
        }

        return $parts;
    }

    /**
     * A stored value as an agent should read it: quoted text, `empty`, or `not set`.
     */
    private static function value(mixed $value): string
    {
        if ($value === null) {
            return 'not set';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        $value = Compact::clean((string) $value);

        if ($value === '') {
            return 'empty';
        }

        return mb_strlen($value, 'UTF-8') > self::VALUE_CHARS
            ? mb_substr($value, 0, self::VALUE_CHARS - 3, 'UTF-8') . '...'
            : $value;
    }

    /**
     * An exact count, for structure such as fields. Content counts go through Band.
     */
    private static function count(int $count, string $noun): string
    {
        return $count . ' ' . $noun . ($count === 1 ? '' : 's');
    }

    private static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    /**
     * @return list<string>
     */
    private static function idList(mixed $value): array
    {
        return is_array($value) ? array_values(array_map('strval', array_filter($value, 'is_string'))) : [];
    }
}
