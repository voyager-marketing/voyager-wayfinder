<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Render\Ai\Facts;
use Voyager\Wayfinder\Scanners\ContentModelScanner;

/**
 * ACF field details: the sub fields of a repeater or group, the layouts of a
 * flexible content field, and field groups located on a page template.
 */
final class AcfFieldDetailsTest extends TestCase
{
    private const LANDING = 'page_template == page-templates/landing.php';

    public function testRepeatersCarryTheirSubFieldsAndFlexibleFieldsTheirLayouts(): void
    {
        $section = self::scan();
        $phases = $section->item('field:project.project_phases');
        $sections = $section->item('field:page.page_sections');

        $this->assertNotNull($phases);
        $this->assertSame('repeater', $phases->facts['type']);
        $this->assertSame(['phase_name', 'phase_weeks'], $phases->facts['sub_fields'], 'Sorted, whatever order ACF holds them in.');
        $this->assertArrayNotHasKey('layouts', $phases->facts);

        $this->assertNotNull($sections);
        $this->assertSame('flexible_content', $sections->facts['type']);
        $this->assertSame(['cta_band', 'hero'], $sections->facts['layouts']);
        $this->assertArrayNotHasKey('sub_fields', $sections->facts);
    }

    public function testAPlainFieldKeepsExactlyTheFactsItHadBefore(): void
    {
        $summary = self::scan()->item('field:project.project_summary');

        $this->assertNotNull($summary);
        $this->assertSame(
            [
                'group'           => 'Project details',
                'group_edit_path' => 'defined in code',
                'in_rest'         => true,
                'required'        => false,
                'show_in_rest'    => true,
                'source'          => 'acf',
                'type'            => 'text',
            ],
            $summary->facts
        );
    }

    public function testAPageTemplateGroupDescribesPageFieldsAndKeepsItsLocation(): void
    {
        $section = self::scan();
        $featured = $section->item('field:page.featured_projects');
        $sections = $section->item('field:page.page_sections');

        $this->assertNotNull($featured);
        $this->assertSame('relationship', $featured->facts['type']);
        $this->assertSame([self::LANDING], $featured->facts['location']);
        $this->assertSame(['pt:page'], $featured->refs);
        $this->assertSame('post.php?post=90010&action=edit', $featured->facts['group_edit_path']);

        $this->assertSame([self::LANDING], $sections?->facts['location']);
        $this->assertContains('field:page.page_sections', $section->item('pt:page')?->refs ?? []);
        $this->assertNull($section->item('field:project.page_sections'), 'A page template names pages only.');
    }

    public function testTheIndexLineNamesSubFieldsAndLayouts(): void
    {
        $section = self::scan();
        $phases = $section->item('field:project.project_phases');
        $sections = $section->item('field:page.page_sections');
        $this->assertNotNull($phases);
        $this->assertNotNull($sections);

        $this->assertSame('repeater; ACF group Project details; sub fields phase_name, phase_weeks', Facts::describe($phases));
        $this->assertSame('flexible_content; ACF group Landing page; layouts cta_band, hero', Facts::describe($sections));
    }

    public function testAnAcfFieldBecomesARowWithItsSubFieldAndLayoutNames(): void
    {
        $repeater = ContentModelScanner::acfFieldRow([
            'name'       => 'project_phases',
            'label'      => 'Phases',
            'type'       => 'repeater',
            'required'   => 0,
            'sub_fields' => [['name' => 'phase_weeks', 'type' => 'number'], ['name' => 'phase_name', 'type' => 'text'], ['name' => '']],
        ]);
        $flexible = ContentModelScanner::acfFieldRow([
            'name'    => 'page_sections',
            'label'   => 'Page sections',
            'type'    => 'flexible_content',
            'layouts' => ['layout_b' => ['name' => 'hero', 'sub_fields' => []], 'layout_a' => ['name' => 'cta_band']],
        ]);
        $plain = ContentModelScanner::acfFieldRow(['name' => 'project_summary', 'label' => 'Summary', 'type' => 'text', 'required' => 1]);

        $this->assertSame(['name' => 'project_phases', 'label' => 'Phases', 'type' => 'repeater', 'required' => false, 'sub_fields' => ['phase_name', 'phase_weeks']], $repeater);
        $this->assertSame(['name' => 'page_sections', 'label' => 'Page sections', 'type' => 'flexible_content', 'required' => false, 'layouts' => ['cta_band', 'hero']], $flexible);
        $this->assertSame(['name' => 'project_summary', 'label' => 'Summary', 'type' => 'text', 'required' => true], $plain);
    }

    private static function scan(): Section
    {
        $postType = static fn (string $slug, string $label): array => [
            'slug'         => $slug,
            'label'        => $label,
            'menu_name'    => $label,
            'public'       => true,
            'show_ui'      => true,
            'has_archive'  => false,
            'rewrite_slug' => null,
            'supports'     => ['title', 'editor'],
            'hierarchical' => false,
            'show_in_rest' => true,
            'counts'       => ['publish' => 3],
        ];

        $groups = [
            [
                'key'          => 'group_project',
                'title'        => 'Project details',
                'id'           => 0,
                'active'       => true,
                'show_in_rest' => true,
                'location'     => [[['param' => 'post_type', 'operator' => '==', 'value' => 'project']]],
                'fields'       => [
                    ['name' => 'project_phases', 'label' => 'Phases', 'type' => 'repeater', 'required' => false, 'sub_fields' => ['phase_name', 'phase_weeks']],
                    ['name' => 'project_summary', 'label' => 'Summary', 'type' => 'text', 'required' => false],
                ],
            ],
            [
                'key'          => 'group_landing',
                'title'        => 'Landing page',
                'id'           => 90010,
                'active'       => true,
                'show_in_rest' => true,
                'location'     => [[['param' => 'page_template', 'operator' => '==', 'value' => 'page-templates/landing.php']]],
                'fields'       => [
                    ['name' => 'page_sections', 'label' => 'Page sections', 'type' => 'flexible_content', 'required' => false, 'layouts' => ['cta_band', 'hero']],
                    ['name' => 'featured_projects', 'label' => 'Featured projects', 'type' => 'relationship', 'required' => false],
                ],
            ],
        ];

        return (new ContentModelScanner(
            static fn (): array => [$postType('page', 'Pages'), $postType('project', 'Projects')],
            static fn (): array => [],
            static fn (string $slug): array => [],
            static fn (): array => $groups
        ))->scan();
    }
}
