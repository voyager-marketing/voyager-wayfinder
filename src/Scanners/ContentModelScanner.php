<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use Closure;
use Voyager\Wayfinder\Manifest\IdFormat;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Section;
use WP_Error;

/**
 * `content_model` section: the post types a site edits, their taxonomies, and
 * the fields on each post type from registered meta and, when it is active,
 * ACF.
 *
 * Every WordPress call sits behind a lookup that returns plain arrays, so the
 * unit suite passes closures over a fixture and needs no database. The pattern
 * is ported from voyager-orbit src/Modules/Blocks/Services/ReferenceResolver.php
 * @ abe7d26; the defaults are the static `lookup*` methods.
 *
 * Out of scope here: users, comments, term meta, user meta, options, and ACF
 * groups whose location names no post type (users, terms, options pages). A
 * group located on a page template (`page_template ==`) describes pages.
 *
 * An ACF repeater or group field names its sub fields (`sub_fields`) and a
 * flexible content field its layouts (`layouts`). Other fields carry neither.
 *
 * On a classic theme, each ACF options page (acf_add_options_page()) is an
 * `optpage:<menu slug>` item that edits at its own screen and names the field
 * groups located on it and their fields. A block theme has none.
 *
 * Post types and taxonomies carry `defined_in`, the file that registered
 * them relative to wp-content (see Registrations), `core` for WordPress's
 * own, or null when Wayfinder loaded too late to see it.
 *
 * A public post type whose posts an active page builder owns carries the
 * `builder_docs` stat, published documents by builder slug (see Builders).
 * It is content, so it sits beside `counts`, outside every hash.
 *
 * @phpstan-type OptionsPageRow array{slug: string, title: string, parent: string|null}
 * @phpstan-type PostTypeRow array{slug: string, label: string, menu_name: string, public: bool, show_ui: bool, has_archive: bool|string, rewrite_slug: string|null, supports: list<string>, hierarchical: bool, show_in_rest: bool, counts: array<string, int>, defined_in?: string|null, builder_docs?: array<string, int>}
 * @phpstan-type TaxonomyRow array{slug: string, label: string, object_types: list<string>, public: bool, show_ui: bool, hierarchical: bool, show_in_rest: bool, terms: int, defined_in?: string|null}
 * @phpstan-type MetaRow array{type: string, single: bool, show_in_rest: bool, label: string}
 * @phpstan-type AcfRule array{param: string, operator: string, value: string}
 * @phpstan-type AcfFieldRow array{name: string, label: string, type: string, required: bool, sub_fields?: list<string>, layouts?: list<string>}
 * @phpstan-type AcfGroupRow array{key: string, title: string, id: int, active: bool, show_in_rest: bool, location: list<list<AcfRule>>, fields: list<AcfFieldRow>}
 */
final class ContentModelScanner implements Scanner
{
    public const SECTION = 'content_model';

    /** Post types WordPress keeps its own records in. Never part of a site's content model. */
    public const INTERNAL_POST_TYPES = [
        'custom_css',
        'customize_changeset',
        'nav_menu_item',
        'oembed_cache',
        'revision',
        'user_request',
        'wp_block',
        'wp_font_face',
        'wp_font_family',
        'wp_global_styles',
        'wp_navigation',
        'wp_template',
        'wp_template_part',
    ];

    /**
     * Post types a plugin keeps its own settings in. ACF shows these in
     * wp-admin, but they hold field and type definitions, not content.
     */
    public const PLUGIN_POST_TYPES = [
        'acf-field',
        'acf-field-group',
        'acf-post-type',
        'acf-taxonomy',
        'acf-ui-options-page',
    ];

    /** The post statuses counted for every post type. */
    private const COUNTED_STATUSES = ['draft', 'private', 'publish'];

    /** ACF field types that lay out a form and store nothing. */
    private const ACF_LAYOUT_TYPES = ['accordion', 'message', 'tab'];

    /** How the summary words a registered meta type. */
    private const TYPE_WORDS = ['string' => 'text'];

    /** @var Closure(): list<PostTypeRow> */
    private Closure $postTypes;

    /** @var Closure(): list<TaxonomyRow> */
    private Closure $taxonomies;

    /** @var Closure(string): array<string, MetaRow> */
    private Closure $registeredMeta;

    /** @var Closure(): (list<AcfGroupRow>|null) */
    private Closure $acfGroups;

    /** @var Closure(): (list<OptionsPageRow>|null) */
    private Closure $optionsPages;

    /**
     * @param (callable(): list<PostTypeRow>)|null              $postTypes      Every registered post type. Defaults to lookupPostTypes().
     * @param (callable(): list<TaxonomyRow>)|null              $taxonomies     Every registered taxonomy. Defaults to lookupTaxonomies().
     * @param (callable(string): array<string, MetaRow>)|null   $registeredMeta Meta registered for one post type, by key. Defaults to lookupRegisteredMeta().
     * @param (callable(): (list<AcfGroupRow>|null))|null       $acfGroups      ACF field groups, or null when ACF is not active. Defaults to lookupAcfGroups().
     * @param (callable(): (list<OptionsPageRow>|null))|null    $optionsPages   ACF options pages, or null on a block theme or without them. Defaults to lookupOptionsPages().
     */
    public function __construct(
        ?callable $postTypes = null,
        ?callable $taxonomies = null,
        ?callable $registeredMeta = null,
        ?callable $acfGroups = null,
        ?callable $optionsPages = null
    ) {
        $this->postTypes = $postTypes !== null ? Closure::fromCallable($postTypes) : self::lookupPostTypes(...);
        $this->taxonomies = $taxonomies !== null ? Closure::fromCallable($taxonomies) : self::lookupTaxonomies(...);
        $this->registeredMeta = $registeredMeta !== null ? Closure::fromCallable($registeredMeta) : self::lookupRegisteredMeta(...);
        $this->acfGroups = $acfGroups !== null ? Closure::fromCallable($acfGroups) : self::lookupAcfGroups(...);
        $this->optionsPages = $optionsPages !== null ? Closure::fromCallable($optionsPages) : self::lookupOptionsPages(...);
    }

    public function section(): string
    {
        return self::SECTION;
    }

    public function scan(): Section
    {
        $postTypes = $this->postTypesInModel();
        $taxonomies = $this->taxonomiesInModel($postTypes);
        $groups = ($this->acfGroups)() ?? [];
        usort($groups, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));
        $fields = $this->fields($postTypes, $groups);

        $taxonomiesByType = [];
        foreach ($taxonomies as $slug => [$taxonomy, $objectTypes]) {
            foreach ($objectTypes as $objectType) {
                $taxonomiesByType[$objectType][] = $slug;
            }
        }

        $items = [];

        foreach ($postTypes as $slug => $postType) {
            $typeFields = $fields[$slug] ?? [];
            $typeTaxonomies = $taxonomiesByType[$slug] ?? [];

            $items[] = $this->postTypeItem($postType, $typeFields, $typeTaxonomies);

            foreach ($typeFields as $key => [$label, $facts]) {
                $items[] = new Item(
                    self::fieldId($slug, $key),
                    'field',
                    $label,
                    $facts,
                    self::postTypeEditPath($postType),
                    ['pt:' . $slug]
                );
            }
        }

        foreach ($taxonomies as [$taxonomy, $objectTypes]) {
            $items[] = $this->taxonomyItem($taxonomy, $objectTypes);
        }

        return new Section(self::SECTION, [...$items, ...$this->optionsPageItems($groups)]);
    }

    /**
     * One item per ACF options page, with the active field groups located on
     * it (`options_page == <menu slug>` in any of their OR groups) and the
     * fields those groups hold.
     *
     * @param list<AcfGroupRow> $groups
     * @return list<Item>
     */
    private function optionsPageItems(array $groups): array
    {
        $pages = ($this->optionsPages)() ?? [];
        $slugs = array_column($pages, 'slug');
        $items = [];

        foreach ($pages as $page) {
            $id = 'optpage:' . $page['slug'];

            if ($page['slug'] === '' || !IdFormat::isValid($id) || isset($items[$id])) {
                continue;
            }

            $titles = [];
            $fields = [];

            foreach ($groups as $group) {
                if (!$group['active'] || !self::isOnOptionsPage($group['location'], $page['slug'])) {
                    continue;
                }

                $titles[] = $group['title'];

                foreach ($group['fields'] as $field) {
                    if ($field['name'] !== '' && !in_array($field['type'], self::ACF_LAYOUT_TYPES, true)) {
                        $fields[] = $field['name'];
                    }
                }
            }

            $titles = array_values(array_unique($titles));
            $fields = array_values(array_unique($fields));
            sort($titles, SORT_STRING);
            sort($fields, SORT_STRING);

            $items[$id] = new Item(
                $id,
                'options_page',
                $page['title'] !== '' ? $page['title'] : $page['slug'],
                [
                    'field_groups' => $titles,
                    'fields'       => $fields,
                    'parent'       => $page['parent'],
                ],
                self::optionsPageEditPath($page, $slugs)
            );
        }

        return array_values($items);
    }

    /**
     * @param list<list<AcfRule>> $location
     */
    private static function isOnOptionsPage(array $location, string $slug): bool
    {
        foreach ($location as $andRules) {
            foreach ($andRules as $rule) {
                if ($rule['param'] === 'options_page' && $rule['operator'] === '==' && $rule['value'] === $slug) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Where an options page opens, as core's menu_page_url() builds it: under
     * its parent's screen when the parent is a wp-admin file
     * (`options-general.php?page=<slug>`), else `admin.php?page=<slug>`.
     *
     * @param OptionsPageRow $page
     * @param list<string>   $slugs Every options page's menu slug.
     */
    private static function optionsPageEditPath(array $page, array $slugs): string
    {
        $parent = $page['parent'];

        if ($parent !== null && str_contains($parent, '.php') && !in_array($parent, $slugs, true)) {
            return $parent . (str_contains($parent, '?') ? '&' : '?') . 'page=' . $page['slug'];
        }

        return 'admin.php?page=' . $page['slug'];
    }

    /**
     * Public or editable in wp-admin, and not a record type of WordPress or a plugin.
     *
     * @return array<string, PostTypeRow> Keyed and sorted by slug.
     */
    private function postTypesInModel(): array
    {
        $postTypes = [];

        foreach (($this->postTypes)() as $postType) {
            if (
                in_array($postType['slug'], self::INTERNAL_POST_TYPES, true)
                || in_array($postType['slug'], self::PLUGIN_POST_TYPES, true)
            ) {
                continue;
            }

            if ($postType['public'] || $postType['show_ui']) {
                $postTypes[$postType['slug']] = $postType;
            }
        }

        ksort($postTypes, SORT_STRING);

        return $postTypes;
    }

    /**
     * Public or editable taxonomies attached to at least one post type in the
     * model. That drops the ones WordPress uses on its own record types
     * (nav_menu, wp_theme, wp_pattern_category, ...).
     *
     * @param array<string, PostTypeRow> $postTypes
     * @return array<string, array{0: TaxonomyRow, 1: list<string>}> Taxonomy and the post types it is on, in
     *                                                               registration order, keyed and sorted by slug.
     */
    private function taxonomiesInModel(array $postTypes): array
    {
        $taxonomies = [];

        foreach (($this->taxonomies)() as $taxonomy) {
            $objectTypes = array_values(array_unique(array_filter(
                $taxonomy['object_types'],
                static fn (string $type): bool => isset($postTypes[$type])
            )));

            if ($objectTypes !== [] && ($taxonomy['public'] || $taxonomy['show_ui'])) {
                $taxonomies[$taxonomy['slug']] = [$taxonomy, $objectTypes];
            }
        }

        ksort($taxonomies, SORT_STRING);

        return $taxonomies;
    }

    /**
     * Registered meta first, then ACF. When both describe one meta key, the
     * registered meta entry stays: it is what WordPress itself enforces.
     *
     * @param array<string, PostTypeRow> $postTypes
     * @param list<AcfGroupRow>          $groups    Sorted by key.
     * @return array<string, array<string, array{0: string, 1: array<string, mixed>}>> Post type => key => [label, facts].
     */
    private function fields(array $postTypes, array $groups): array
    {
        $fields = [];

        foreach ($postTypes as $slug => $postType) {
            // REST only carries registered meta for post types that support custom-fields.
            $metaInRest = $postType['show_in_rest'] && in_array('custom-fields', $postType['supports'], true);

            foreach (($this->registeredMeta)($slug) as $key => $meta) {
                $key = (string) $key;

                // Underscore keys are protected meta: WordPress hides them from the custom fields UI.
                if ($key === '' || str_starts_with($key, '_')) {
                    continue;
                }

                $fields[$slug][$key] = [
                    $meta['label'] !== '' ? $meta['label'] : $key,
                    [
                        'in_rest'      => $meta['show_in_rest'] && $metaInRest,
                        'show_in_rest' => $meta['show_in_rest'],
                        'single'       => $meta['single'],
                        'source'       => 'registered_meta',
                        'type'         => $meta['type'],
                    ],
                ];
            }
        }

        foreach ($groups as $group) {
            if (!$group['active']) {
                continue;
            }

            [$groupTypes, $location] = self::resolveAcfLocation($group['location']);

            $facts = [
                'group'           => $group['title'],
                'group_edit_path' => $group['id'] > 0 ? 'post.php?post=' . $group['id'] . '&action=edit' : 'defined in code',
                'show_in_rest'    => $group['show_in_rest'],
                'source'          => 'acf',
            ];

            if ($location !== null) {
                $facts['location'] = $location;
            }

            foreach ($groupTypes as $slug) {
                if (!isset($postTypes[$slug])) {
                    continue;
                }

                foreach ($group['fields'] as $field) {
                    $key = $field['name'];

                    if ($key === '' || in_array($field['type'], self::ACF_LAYOUT_TYPES, true) || isset($fields[$slug][$key])) {
                        continue;
                    }

                    $fieldFacts = $facts + [
                        'in_rest'  => $group['show_in_rest'] && $postTypes[$slug]['show_in_rest'],
                        'required' => $field['required'],
                        'type'     => $field['type'],
                    ];

                    // Only a field that has them carries these, so every other field keeps its facts.
                    foreach (['sub_fields', 'layouts'] as $detail) {
                        if (($field[$detail] ?? []) !== []) {
                            $fieldFacts[$detail] = $field[$detail];
                        }
                    }

                    ksort($fieldFacts, SORT_STRING);

                    $fields[$slug][$key] = [$field['label'] !== '' ? $field['label'] : $key, $fieldFacts];
                }
            }
        }

        foreach ($fields as &$typeFields) {
            ksort($typeFields, SORT_STRING);
        }
        unset($typeFields);

        return $fields;
    }

    /**
     * ACF locations are OR groups of AND rules. A field group belongs to every
     * post type named by a `post_type ==` rule, and to pages for a
     * `page_template ==` rule, since page templates belong to pages. Any rule
     * but `post_type ==` narrows or replaces that, so the whole location is
     * kept raw beside it.
     *
     * @param list<list<AcfRule>> $location
     * @return array{0: list<string>, 1: list<string>|null} Post type slugs, and the raw location (one line
     *                                                      per OR group) when it says more than the post types.
     */
    private static function resolveAcfLocation(array $location): array
    {
        $postTypes = [];
        $lines = [];
        $onlyPostTypes = true;

        foreach ($location as $andRules) {
            $parts = [];

            foreach ($andRules as $rule) {
                $parts[] = trim($rule['param'] . ' ' . $rule['operator'] . ' ' . $rule['value']);

                if ($rule['param'] === 'post_type' && $rule['operator'] === '==') {
                    $postTypes[] = $rule['value'];
                } else {
                    $onlyPostTypes = false;

                    if ($rule['param'] === 'page_template' && $rule['operator'] === '==') {
                        $postTypes[] = 'page';
                    }
                }
            }

            if (count($andRules) > 1) {
                $onlyPostTypes = false;
            }

            $lines[] = implode(' and ', $parts);
        }

        $postTypes = array_values(array_unique($postTypes));
        sort($postTypes, SORT_STRING);

        return [$postTypes, $onlyPostTypes ? null : $lines];
    }

    /**
     * @param PostTypeRow                                       $postType
     * @param array<string, array{0: string, 1: array<string, mixed>}> $fields     Key => [label, facts], sorted by key.
     * @param list<string>                                      $taxonomies Taxonomy slugs, sorted.
     */
    private function postTypeItem(array $postType, array $fields, array $taxonomies): Item
    {
        $slug = $postType['slug'];

        $counts = [];
        $statuses = $slug === 'attachment' ? [...self::COUNTED_STATUSES, 'inherit'] : self::COUNTED_STATUSES;
        foreach ($statuses as $status) {
            $counts[$status] = $postType['counts'][$status] ?? 0;
        }
        ksort($counts, SORT_STRING);

        $supports = array_values(array_unique($postType['supports']));
        sort($supports, SORT_STRING);

        $refs = array_map(static fn (string $tax): string => 'tax:' . $tax, $taxonomies);
        foreach (array_keys($fields) as $key) {
            $refs[] = self::fieldId($slug, (string) $key);
        }

        return new Item(
            'pt:' . $slug,
            'post_type',
            $postType['label'],
            [
                'defined_in'   => $postType['defined_in'] ?? null,
                'has_archive'  => $postType['has_archive'],
                'hierarchical' => $postType['hierarchical'],
                'menu_name'    => $postType['menu_name'],
                'public'       => $postType['public'],
                'rewrite_slug' => $postType['rewrite_slug'],
                'show_in_rest' => $postType['show_in_rest'],
                'show_ui'      => $postType['show_ui'],
                'summary'      => self::summary($postType, $fields, $taxonomies),
                'supports'     => $supports,
            ],
            self::postTypeEditPath($postType),
            $refs,
            ['counts' => $counts] + self::builderDocs($postType)
        );
    }

    /**
     * The `builder_docs` stat: published posts of the type each active page
     * builder owns, by builder slug (see Builders). Left out when no builder
     * owns any, so a site without one has the stats it always had.
     *
     * @param PostTypeRow $postType
     * @return array<string, array<string, int>>
     */
    private static function builderDocs(array $postType): array
    {
        $docs = array_filter($postType['builder_docs'] ?? [], static fn (int $count): bool => $count > 0);
        ksort($docs, SORT_STRING);

        return $docs !== [] ? ['builder_docs' => $docs] : [];
    }

    /**
     * @param TaxonomyRow  $taxonomy
     * @param list<string> $objectTypes Post types in the model it is attached to, in registration order.
     */
    private function taxonomyItem(array $taxonomy, array $objectTypes): Item
    {
        $sortedTypes = array_values(array_unique($taxonomy['object_types']));
        sort($sortedTypes, SORT_STRING);

        $edit = $taxonomy['show_ui']
            ? 'edit-tags.php?taxonomy=' . $taxonomy['slug'] . '&post_type=' . $objectTypes[0]
            : null;

        return new Item(
            'tax:' . $taxonomy['slug'],
            'taxonomy',
            $taxonomy['label'],
            [
                'defined_in'   => $taxonomy['defined_in'] ?? null,
                'hierarchical' => $taxonomy['hierarchical'],
                'object_types' => $sortedTypes,
                'public'       => $taxonomy['public'],
                'show_in_rest' => $taxonomy['show_in_rest'],
                'show_ui'      => $taxonomy['show_ui'],
            ],
            $edit,
            array_map(static fn (string $type): string => 'pt:' . $type, $objectTypes),
            ['terms' => $taxonomy['terms']]
        );
    }

    /**
     * One plain sentence per post type, e.g. "Services. Fields: duration
     * (text), price from (number). Grouped by service area." Built from slugs
     * and keys, not translated labels, apart from the post type's own label,
     * which is English for a core type (see EnglishLabels).
     * No numbers: counts live in stats, and renderers add them from there.
     *
     * @param PostTypeRow                                       $postType
     * @param array<string, array{0: string, 1: array<string, mixed>}> $fields
     * @param list<string>                                      $taxonomies
     */
    private static function summary(array $postType, array $fields, array $taxonomies): string
    {
        $summary = $postType['label'] . '.';

        if ($fields !== []) {
            $words = [];
            foreach ($fields as $key => [, $facts]) {
                $type = is_string($facts['type'] ?? null) ? $facts['type'] : '';
                $notes = [self::TYPE_WORDS[$type] ?? $type];

                if (($facts['in_rest'] ?? false) !== true) {
                    $notes[] = 'not in REST';
                }

                $words[] = self::humanize((string) $key) . ' (' . implode(', ', array_filter($notes)) . ')';
            }

            $summary .= ' Fields: ' . implode(', ', $words) . '.';
        }

        if ($taxonomies !== []) {
            $summary .= ' Grouped by ' . self::joinWords(array_map(self::humanize(...), $taxonomies)) . '.';
        }

        return $summary;
    }

    /**
     * Where the posts of a type are listed in wp-admin. Null when the type has no admin screen.
     *
     * @param PostTypeRow $postType
     */
    private static function postTypeEditPath(array $postType): ?string
    {
        if (!$postType['show_ui']) {
            return null;
        }

        return match ($postType['slug']) {
            'post'       => 'edit.php',
            'attachment' => 'upload.php',
            default      => 'edit.php?post_type=' . $postType['slug'],
        };
    }

    private static function fieldId(string $postType, string $key): string
    {
        return 'field:' . $postType . '.' . $key;
    }

    private static function humanize(string $slug): string
    {
        return str_replace(['_', '-'], ' ', $slug);
    }

    /**
     * @param list<string> $words
     */
    private static function joinWords(array $words): string
    {
        $last = array_pop($words);

        return $words === [] ? (string) $last : implode(', ', $words) . ' and ' . $last;
    }

    /**
     * Default lookup: every registered post type. Core's own are labelled in
     * English (see EnglishLabels).
     *
     * @return list<PostTypeRow>
     */
    public static function lookupPostTypes(): array
    {
        $rows = [];
        $builders = WordPressBuilders::active();
        $english = new EnglishLabels();

        foreach (get_post_types([], 'objects') as $type) {
            $counts = [];
            foreach (get_object_vars(wp_count_posts($type->name)) as $status => $count) {
                $counts[(string) $status] = (int) $count;
            }

            $rewrite = $type->rewrite;
            $hasArchive = $type->has_archive;

            $rows[] = [
                'slug'         => $type->name,
                'label'        => $english->postType($type->name, (string) $type->label),
                'menu_name'    => $english->postType($type->name, (string) ($type->labels->menu_name ?? $type->label)),
                'public'       => (bool) $type->public,
                'show_ui'      => (bool) $type->show_ui,
                'has_archive'  => is_string($hasArchive) && $hasArchive !== '' ? $hasArchive : (bool) $hasArchive,
                'rewrite_slug' => is_array($rewrite) && is_string($rewrite['slug'] ?? null) ? $rewrite['slug'] : null,
                'supports'     => array_map('strval', array_keys(get_all_post_type_supports($type->name))),
                'hierarchical' => (bool) $type->hierarchical,
                'show_in_rest' => (bool) $type->show_in_rest,
                'counts'       => $counts,
                'defined_in'   => $type->_builtin ? SourceFile::CORE : Registrations::postType($type->name),
                'builder_docs' => $type->public && !$builders->isEmpty() ? WordPressBuilders::publishedCounts($type->name, $builders) : [],
            ];
        }

        return $rows;
    }

    /**
     * Default lookup: every registered taxonomy, with its term count. Core's
     * own are labelled in English (see EnglishLabels).
     *
     * @return list<TaxonomyRow>
     */
    public static function lookupTaxonomies(): array
    {
        $rows = [];
        $english = new EnglishLabels();

        foreach (get_taxonomies([], 'objects') as $taxonomy) {
            $terms = wp_count_terms(['taxonomy' => $taxonomy->name, 'hide_empty' => false]);

            $rows[] = [
                'slug'         => $taxonomy->name,
                'label'        => $english->taxonomy($taxonomy->name, (string) $taxonomy->label),
                'object_types' => array_values(array_map('strval', (array) $taxonomy->object_type)),
                'public'       => (bool) $taxonomy->public,
                'show_ui'      => (bool) $taxonomy->show_ui,
                'hierarchical' => (bool) $taxonomy->hierarchical,
                'show_in_rest' => (bool) $taxonomy->show_in_rest,
                'terms'        => $terms instanceof WP_Error ? 0 : (int) $terms,
                'defined_in'   => $taxonomy->_builtin ? SourceFile::CORE : Registrations::taxonomy($taxonomy->name),
            ];
        }

        return $rows;
    }

    /**
     * Default lookup: meta registered for one post type. Meta registered for
     * every post type at once is not included.
     *
     * @return array<string, MetaRow>
     */
    public static function lookupRegisteredMeta(string $postType): array
    {
        $rows = [];

        foreach (get_registered_meta_keys('post', $postType) as $key => $args) {
            $rows[(string) $key] = [
                'type'         => is_string($args['type'] ?? null) ? $args['type'] : 'string',
                'single'       => (bool) ($args['single'] ?? false),
                // show_in_rest may be an array carrying a schema; any non-empty value exposes the key.
                'show_in_rest' => !empty($args['show_in_rest']),
                'label'        => is_string($args['label'] ?? null) ? $args['label'] : '',
            ];
        }

        return $rows;
    }

    /**
     * Default lookup: ACF field groups and their top-level fields. Null when
     * ACF is not active, so "no ACF" stays distinct from "no groups".
     *
     * @return list<AcfGroupRow>|null
     */
    public static function lookupAcfGroups(): ?array
    {
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return null;
        }

        $rows = [];

        foreach (acf_get_field_groups() as $group) {
            $fields = [];
            foreach (acf_get_fields($group) ?: [] as $field) {
                $fields[] = self::acfFieldRow((array) $field);
            }

            $location = [];
            foreach ((array) ($group['location'] ?? []) as $andRules) {
                $rules = [];
                foreach ((array) $andRules as $rule) {
                    $rule = (array) $rule;
                    $rules[] = [
                        'param'    => self::text($rule['param'] ?? ''),
                        'operator' => self::text($rule['operator'] ?? ''),
                        'value'    => self::text($rule['value'] ?? ''),
                    ];
                }
                $location[] = $rules;
            }

            $rows[] = [
                'key'          => self::text($group['key'] ?? ''),
                'title'        => self::text($group['title'] ?? ''),
                // Database groups carry their post ID; local (PHP or JSON) groups carry 0.
                'id'           => (int) ($group['ID'] ?? 0),
                'active'       => !isset($group['active']) || (bool) $group['active'],
                'show_in_rest' => !empty($group['show_in_rest']),
                'location'     => $location,
                'fields'       => $fields,
            ];
        }

        return $rows;
    }

    /**
     * One top-level ACF field as acf_get_fields() returns it: its name,
     * label, type and whether it is required, plus the names of a repeater's
     * or group's sub fields and of a flexible content field's layouts, sorted
     * and only when there are any.
     *
     * @param array<mixed> $field
     * @return AcfFieldRow
     */
    public static function acfFieldRow(array $field): array
    {
        $row = [
            'name'     => self::text($field['name'] ?? ''),
            'label'    => self::text($field['label'] ?? ''),
            'type'     => self::text($field['type'] ?? ''),
            'required' => !empty($field['required']),
        ];

        foreach (['sub_fields', 'layouts'] as $detail) {
            $names = [];

            foreach ((array) ($field[$detail] ?? []) as $entry) {
                $name = self::text(((array) $entry)['name'] ?? '');

                if ($name !== '') {
                    $names[] = $name;
                }
            }

            if ($names !== []) {
                $names = array_values(array_unique($names));
                sort($names, SORT_STRING);
                $row[$detail] = $names;
            }
        }

        return $row;
    }

    /**
     * Default lookup: ACF options pages, as acf_get_options_pages() returns
     * them. Null on a block theme, or when ACF has no options pages API.
     *
     * @return list<OptionsPageRow>|null
     */
    public static function lookupOptionsPages(): ?array
    {
        if (!ClassicTheme::isActive() || !function_exists('acf_get_options_pages')) {
            return null;
        }

        $rows = [];

        foreach ((array) acf_get_options_pages() as $page) {
            $page = (array) $page;
            $parent = self::text($page['parent_slug'] ?? '');

            $rows[] = [
                'slug'   => self::text($page['menu_slug'] ?? ''),
                'title'  => self::text($page['page_title'] ?? ''),
                'parent' => $parent !== '' ? $parent : null,
            ];
        }

        return $rows;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
