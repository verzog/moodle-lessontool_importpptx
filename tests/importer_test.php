<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Unit tests for the PowerPoint importer.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx;

use local_lessonimportpptx\pptx\package;
use local_lessonimportpptx\pptx\slide;
use local_lessonimportpptx\pptx\html_builder;
use local_lessonimportpptx\pptx\block;

/**
 * Tests extraction and lesson-page creation against a synthetic fixture deck.
 *
 * The fixture (tests/fixtures/sample.pptx) contains one slide of each supported
 * case: a title slide, a bullets+image slide, three captioned images, a SmartArt
 * slide, a table slide, a section divider, an ordinary follower, a decorative
 * badge, and a no-title fallback slide.
 *
 * @covers \local_lessonimportpptx\importer
 */
final class importer_test extends \advanced_testcase {
    /**
     * Returns the absolute path to the fixture deck.
     *
     * @return string
     */
    private function fixture(): string {
        return __DIR__ . '/fixtures/sample.pptx';
    }

    /**
     * Parses every slide of the fixture into page objects (no database needed).
     *
     * @return \stdClass[] One built-page object per slide, in order.
     */
    private function build_all(): array {
        $package = new package($this->fixture());
        $builder = new html_builder('#442980');
        $pages = [];
        foreach ($package->get_slide_paths() as $path) {
            $parsed = (new slide($package, $path))->parse();
            $pages[] = $builder->build($parsed);
        }
        $package->close();
        return $pages;
    }

    /**
     * The fixture is recognised as a nine-slide presentation.
     */
    public function test_count_slides(): void {
        $this->resetAfterTest();
        $file = $this->make_stored_file();
        $this->assertSame(9, importer::count_slides($file));
    }

    /**
     * Title placeholders map to page titles; a short line is promoted when absent.
     */
    public function test_titles_and_fallback(): void {
        $pages = $this->build_all();
        $titles = array_map(static fn($c) => $c->title, $pages);
        $this->assertSame('Presentation Title', $titles[0]);
        $this->assertSame('Overview', $titles[1]);
        $this->assertSame('Clock', $titles[2]);
        // Slide 9 has no title placeholder: the first short line is promoted.
        $this->assertSame('Short Heading', $titles[8]);
    }

    /**
     * Text becomes lists and paragraphs; bold survives; a decorative badge is dropped.
     */
    public function test_text_lists_bold_and_badges(): void {
        $pages = $this->build_all();
        // Two paragraphs become a list; the bold run is preserved.
        $this->assertStringContainsString('<ul>', $pages[1]->html);
        $this->assertStringContainsString('<strong>First point</strong>', $pages[1]->html);
        // The two-line "Real / content" keeps its line break.
        $this->assertStringContainsString('Real<br>content', $pages[7]->html);
        // The decorative "AT" badge (<= 4 chars) is not emitted.
        $this->assertStringNotContainsString('>AT<', $pages[7]->html);
    }

    /**
     * Reading order keeps a same-row set left-to-right (13:00, 14:00, 15:00).
     */
    public function test_reading_order_left_to_right(): void {
        $pages = $this->build_all();
        $html = $pages[2]->html;
        $pos1 = strpos($html, '13:00');
        $pos2 = strpos($html, '14:00');
        $pos3 = strpos($html, '15:00');
        $this->assertNotFalse($pos1);
        $this->assertLessThan($pos2, $pos1);
        $this->assertLessThan($pos3, $pos2);
    }

    /**
     * Consecutive images form a Bootstrap grid, with preceding short lines as captions.
     */
    public function test_image_grid_with_captions(): void {
        $pages = $this->build_all();
        $html = $pages[2]->html;
        $this->assertStringContainsString('local-lessonimportpptx-grid', $html);
        $this->assertStringContainsString('col-12 col-md-6 col-lg-4', $html);
        $this->assertStringContainsString('<div class="local-lessonimportpptx-cap">13:00</div>', $html);
        $this->assertCount(3, $pages[2]->images);
    }

    /**
     * Two blocks sharing a horizontal band are laid out as side-by-side columns.
     */
    public function test_side_by_side_blocks_become_columns(): void {
        $builder = new html_builder('#442980');
        $parsed = (object) [
            'title' => 'Two up',
            'section' => null,
            'blocks' => [
                new block(block::TYPE_TEXT, 2000000, 0, ['Left column paragraph.']),
                new block(block::TYPE_IMAGE, 2000000, 7000000, 'ppt/media/image1.png'),
            ],
        ];
        $out = $builder->build($parsed);
        $this->assertStringContainsString('local-lessonimportpptx-cols', $out->html);
        $this->assertStringContainsString('col-12 col-md-6', $out->html);
        $this->assertStringContainsString('<p>Left column paragraph.</p>', $out->html);
        $this->assertStringContainsString('@@PLUGINFILE@@/image1.png', $out->html);
    }

    /**
     * A lone image is wrapped in a centred, size-capped figure, not left full-bleed.
     */
    public function test_single_image_is_a_constrained_figure(): void {
        $builder = new html_builder('#442980');
        $parsed = (object) [
            'title' => 'One image',
            'section' => null,
            'blocks' => [
                new block(block::TYPE_IMAGE, 3000000, 3000000, 'ppt/media/pic.png'),
            ],
        ];
        $out = $builder->build($parsed);
        $this->assertStringContainsString('local-lessonimportpptx-figure', $out->html);
        $this->assertStringNotContainsString('local-lessonimportpptx-grid', $out->html);
        $this->assertCount(1, $out->images);
    }

    /**
     * Blocks sharing an x (e.g. a picture fill and its text) stack, not columns.
     */
    public function test_same_x_blocks_are_not_columns(): void {
        $builder = new html_builder('#442980');
        $parsed = (object) [
            'title' => 'Overlay',
            'section' => null,
            'blocks' => [
                new block(block::TYPE_IMAGE, 2000000, 1000000, 'ppt/media/bg.png'),
                new block(block::TYPE_TEXT, 2050000, 1000000, ['Caption over the picture fill.']),
            ],
        ];
        $out = $builder->build($parsed);
        $this->assertStringNotContainsString('local-lessonimportpptx-cols', $out->html);
        $this->assertStringContainsString('<p>Caption over the picture fill.</p>', $out->html);
    }

    /**
     * A bulleted box keeps its outline: indented paragraphs nest under their parent
     * bullet instead of flattening into one flat list.
     */
    public function test_nested_list_from_indent_levels(): void {
        $builder = new html_builder('#442980');
        $text = new block(block::TYPE_TEXT, 2000000, 1000000, [
            'Always consider culture.',
            'Language Barriers',
            'Patients may be confused.',
            'Brain injuries',
            'Bring a support person.',
        ]);
        $text->levels = [0, 0, 1, 0, 1];
        $parsed = (object) ['title' => 'Body', 'section' => null, 'blocks' => [$text]];
        $out = $builder->build($parsed);
        // The heading bullet owns the indented point that follows it.
        $this->assertStringContainsString(
            '<li>Language Barriers<ul><li>Patients may be confused.</li></ul></li>',
            $out->html
        );
        $this->assertStringContainsString(
            '<li>Brain injuries<ul><li>Bring a support person.</li></ul></li>',
            $out->html
        );
    }

    /**
     * A text box that switches bullets off renders as paragraphs, not a bullet list.
     */
    public function test_unbulleted_text_renders_as_paragraphs(): void {
        $builder = new html_builder('#442980');
        $text = new block(block::TYPE_TEXT, 2000000, 1000000, [
            'First sentence of prose.',
            'Second sentence of prose.',
        ]);
        $text->levels = [0, 0];
        $text->nobullets = [true, true];
        $parsed = (object) ['title' => 'Prose', 'section' => null, 'blocks' => [$text]];
        $out = $builder->build($parsed);
        $this->assertStringContainsString('<p>First sentence of prose.</p>', $out->html);
        $this->assertStringContainsString('<p>Second sentence of prose.</p>', $out->html);
        $this->assertStringNotContainsString('<ul>', $out->html);
    }

    /**
     * A box mixing an unbulleted intro line with a bulleted list renders the intro
     * as prose and only the bulleted paragraphs as a list.
     */
    public function test_mixed_prose_and_bullets_split(): void {
        $builder = new html_builder('#442980');
        $text = new block(block::TYPE_TEXT, 2000000, 1000000, [
            'Here is the introduction.',
            'First bullet point.',
            'Second bullet point.',
        ]);
        $text->levels = [0, 0, 0];
        $text->nobullets = [true, false, false];
        $parsed = (object) ['title' => 'Mixed', 'section' => null, 'blocks' => [$text]];
        $out = $builder->build($parsed);
        $this->assertStringContainsString('<p>Here is the introduction.</p>', $out->html);
        $this->assertStringContainsString(
            '<ul><li>First bullet point.</li><li>Second bullet point.</li></ul>',
            $out->html
        );
        // The intro is not swallowed into the list.
        $this->assertStringNotContainsString('<li>Here is the introduction.', $out->html);
    }

    /**
     * A list that starts deeper than a later item still yields well-formed markup:
     * levels are normalised so no bare <ul> sits inside the root without an <li>.
     */
    public function test_nested_list_normalises_disordered_levels(): void {
        $builder = new html_builder('#442980');
        $text = new block(block::TYPE_TEXT, 2000000, 1000000, ['Indented first.', 'Shallower second.']);
        $text->levels = [1, 0];
        $parsed = (object) ['title' => 'Odd', 'section' => null, 'blocks' => [$text]];
        $out = $builder->build($parsed);
        // Well-formed: DOMDocument parses it without repair changing the structure.
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . $out->html . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        // No <ul> is a direct child of another <ul> (which browsers would repair).
        $xpath = new \DOMXPath($doc);
        $this->assertSame(0, $xpath->query('//ul/ul')->length);
        $this->assertStringContainsString('Indented first.', $out->html);
        $this->assertStringContainsString('Shallower second.', $out->html);
    }

    /**
     * A footer placeholder is page furniture and is kept out of the page body,
     * while the body placeholder's indent levels drive nesting.
     */
    public function test_footer_dropped_and_levels_parsed_from_slide(): void {
        $body = '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Body"/><p:cNvSpPr/>'
            . '<p:nvPr><p:ph type="body"/></p:nvPr></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="838200" y="1825625"/><a:ext cx="10515600" cy="4351338"/></a:xfrm></p:spPr>'
            . '<p:txBody><a:bodyPr/>'
            . '<a:p><a:r><a:t>Language Barriers</a:t></a:r></a:p>'
            . '<a:p><a:pPr lvl="1"/><a:r><a:t>Patients may be confused.</a:t></a:r></a:p>'
            . '</p:txBody></p:sp>';
        $footer = '<p:sp><p:nvSpPr><p:cNvPr id="3" name="Footer"/><p:cNvSpPr/>'
            . '<p:nvPr><p:ph type="ftr"/></p:nvPr></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="838200" y="6356350"/><a:ext cx="2419350" cy="365125"/></a:xfrm></p:spPr>'
            . '<p:txBody><a:bodyPr/><a:p><a:r><a:t>MC 10 - Cultural Awareness - PPT 1</a:t></a:r></a:p>'
            . '</p:txBody></p:sp>';

        $page = $this->build_slide($body . $footer);
        $this->assertStringNotContainsString('MC 10', $page->html);
        $this->assertStringContainsString(
            '<li>Language Barriers<ul><li>Patients may be confused.</li></ul></li>',
            $page->html
        );
    }

    /**
     * A footer placeholder with an image fill (branded template) is skipped whole:
     * its repeated picture is not imported either.
     */
    public function test_image_filled_footer_is_dropped(): void {
        $footer = '<p:sp><p:nvSpPr><p:cNvPr id="3" name="Footer"/><p:cNvSpPr/>'
            . '<p:nvPr><p:ph type="ftr"/></p:nvPr></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="838200" y="6356350"/><a:ext cx="2419350" cy="365125"/></a:xfrm>'
            . '<a:blipFill><a:blip r:embed="rId5"/></a:blipFill></p:spPr>'
            . '<p:txBody><a:bodyPr/><a:p><a:r><a:t>Brand strip</a:t></a:r></a:p></p:txBody></p:sp>';

        $page = $this->build_slide(
            $footer,
            ['rId5' => '../media/brand.png'],
            ['ppt/media/brand.png' => 'PNGDATA']
        );
        $this->assertStringNotContainsString('<img', $page->html);
        $this->assertStringNotContainsString('Brand strip', $page->html);
        $this->assertSame([], $page->images);
    }

    /**
     * A text box whose own list style switches bullets off (a:lstStyle/a:buNone)
     * renders as paragraphs even when its paragraphs carry no a:pPr of their own.
     */
    public function test_list_style_bunone_renders_as_paragraphs(): void {
        $sptree = '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Text"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="838200" y="1825625"/><a:ext cx="10515600" cy="4351338"/></a:xfrm></p:spPr>'
            . '<p:txBody><a:bodyPr/>'
            . '<a:lstStyle><a:lvl1pPr><a:buNone/></a:lvl1pPr></a:lstStyle>'
            . '<a:p><a:r><a:t>First prose paragraph.</a:t></a:r></a:p>'
            . '<a:p><a:r><a:t>Second prose paragraph.</a:t></a:r></a:p>'
            . '</p:txBody></p:sp>';

        $page = $this->build_slide($sptree);
        $this->assertStringContainsString('<p>First prose paragraph.</p>', $page->html);
        $this->assertStringContainsString('<p>Second prose paragraph.</p>', $page->html);
        $this->assertStringNotContainsString('<ul>', $page->html);
    }

    /**
     * A paragraph-level bullet (a:buChar) still wins over a list style that
     * switches bullets off, so mixed boxes keep their explicit bullets.
     */
    public function test_paragraph_bullet_overrides_list_style(): void {
        $sptree = '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Text"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="838200" y="1825625"/><a:ext cx="10515600" cy="4351338"/></a:xfrm></p:spPr>'
            . '<p:txBody><a:bodyPr/>'
            . '<a:lstStyle><a:lvl1pPr><a:buNone/></a:lvl1pPr></a:lstStyle>'
            . '<a:p><a:pPr><a:buChar char="•"/></a:pPr><a:r><a:t>Bulleted anyway.</a:t></a:r></a:p>'
            . '<a:p><a:pPr><a:buChar char="•"/></a:pPr><a:r><a:t>Also bulleted.</a:t></a:r></a:p>'
            . '</p:txBody></p:sp>';

        $page = $this->build_slide($sptree);
        $this->assertStringContainsString('<ul><li>Bulleted anyway.</li><li>Also bulleted.</li></ul>', $page->html);
    }

    /**
     * A slide detected as a divider only through its section-header layout is
     * still rendered as a section hero, with the title on the fallback plate.
     */
    public function test_layout_detected_section_gets_plate(): void {
        $nsp = package::NS_P;
        $sptree = '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Title"/><p:cNvSpPr/>'
            . '<p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="838200" y="1825625"/><a:ext cx="10515600" cy="1325625"/></a:xfrm></p:spPr>'
            . '<p:txBody><a:bodyPr/><a:p><a:r><a:t>Getting Around</a:t></a:r></a:p></p:txBody></p:sp>';
        $layout = '<?xml version="1.0"?><p:sldLayout xmlns:p="' . $nsp . '" type="secHead"/>';

        $page = $this->build_slide(
            $sptree,
            ['rId9' => '../slideLayouts/slideLayout1.xml'],
            ['ppt/slideLayouts/slideLayout1.xml' => $layout]
        );
        $this->assertTrue($page->issection);
        $this->assertSame('Getting Around', $page->title);
        $this->assertStringContainsString('local-lessonimportpptx-plate', $page->html);
        // No panel geometry to read a colour from, so the fallback colour is used.
        $this->assertStringContainsString('background-color:#442980', $page->html);
        $this->assertStringContainsString('Getting Around', $page->html);
    }

    /**
     * The cleanup task removes staged uploads past the retention window and
     * keeps fresh ones.
     */
    public function test_cleanup_task_removes_stale_uploads(): void {
        global $DB;
        $this->resetAfterTest();

        $stale = $this->make_named_file('stale.pptx', 'x', 11);
        $fresh = $this->make_named_file('fresh.pptx', 'x', 12);
        $DB->set_field('files', 'timecreated', time() - 8 * DAYSECS, ['id' => $stale->get_id()]);

        (new \local_lessonimportpptx\task\cleanup_task())->execute();

        $fs = get_file_storage();
        $context = \context_system::instance();
        $this->assertFalse(
            $fs->file_exists($context->id, 'local_lessonimportpptx', 'import', 11, '/', 'stale.pptx')
        );
        $this->assertTrue(
            $fs->file_exists($context->id, 'local_lessonimportpptx', 'import', 12, '/', 'fresh.pptx')
        );
    }

    /**
     * WMF/EMF vector images are referenced as PNG (the importer converts them).
     */
    public function test_wmf_image_referenced_as_png(): void {
        $builder = new html_builder('#442980');
        $parsed = (object) [
            'title' => 'Clip',
            'section' => null,
            'blocks' => [
                new block(block::TYPE_IMAGE, 3000000, 3000000, 'ppt/media/image1.wmf'),
            ],
        ];
        $out = $builder->build($parsed);
        $this->assertStringContainsString('@@PLUGINFILE@@/image1.png', $out->html);
        $this->assertStringNotContainsString('image1.wmf', $out->html);
        // The images map still points at the source .wmf for the importer to convert.
        $this->assertSame('ppt/media/image1.wmf', $out->images['image1.png']);
    }

    /**
     * The vector converter fails safely on invalid input, whether or not a tool exists.
     */
    public function test_vector_converter_rejects_invalid_input(): void {
        $this->assertNull(
            \local_lessonimportpptx\graphics\converter::to_png('not a real metafile', 'wmf')
        );
    }

    /**
     * A bitmap wrapped in a WMF converts to PNG in pure PHP, with no external tool.
     */
    public function test_wmf_bitmap_converted_in_pure_php(): void {
        if (!function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD is not available.');
        }
        $png = \local_lessonimportpptx\graphics\converter::to_png($this->bitmap_wmf(), 'wmf');
        $this->assertNotNull($png);
        $this->assertSame("\x89PNG", substr($png, 0, 4));
    }

    /**
     * A failed image drops its layout container so no empty figure/cell remains.
     */
    public function test_failed_image_removes_its_container(): void {
        $method = new \ReflectionMethod(importer::class, 'strip_images');
        $method->setAccessible(true);
        $html = '<p>Keep</p><div class="local-lessonimportpptx-figure">'
            . '<img src="@@PLUGINFILE@@/gone.png" alt="" class="img-fluid"></div>';
        $out = $method->invoke(null, $html, ['gone.png']);
        $this->assertStringContainsString('<p>Keep</p>', $out);
        $this->assertStringNotContainsString('gone.png', $out);
        $this->assertStringNotContainsString('local-lessonimportpptx-figure', $out);
    }

    /**
     * Builds a minimal WMF that wraps a 2x2 24-bit bitmap.
     *
     * @return string The WMF bytes.
     */
    private function bitmap_wmf(): string {
        $bih = pack('VVVvvVVVVVV', 40, 2, 2, 1, 24, 0, 0, 0, 0, 0, 0);
        $row = "\xFF\x00\x00" . "\x00\xFF\x00" . "\x00\x00";
        $dib = $bih . $row . $row;
        $params = pack('V', 0x00CC0020) . pack('v', 0) . pack('vvvv', 2, 2, 0, 0)
            . pack('vvvv', 2, 2, 0, 0) . $dib;
        $recwords = intdiv(6 + strlen($params), 2);
        $stretch = pack('V', $recwords) . pack('v', 0x0F43) . $params;
        $eof = pack('V', 3) . pack('v', 0);
        $totalwords = 9 + intdiv(strlen($stretch), 2) + 3;
        $std = pack('v', 1) . pack('v', 9) . pack('v', 0x0300) . pack('V', $totalwords)
            . pack('v', 0) . pack('V', $recwords) . pack('v', 0);
        $placeable = pack('V', 0x9AC6CDD7) . pack('v', 0) . pack('vvvv', 0, 0, 2, 2)
            . pack('v', 96) . pack('V', 0) . pack('v', 0);
        return $placeable . $std . $stretch . $eof;
    }

    /**
     * A labelled box-and-arrow diagram is reconstructed as an inline SVG figure,
     * keeping the box colours, the arrow and each box's text.
     */
    public function test_diagram_reconstructed_from_labelled_boxes(): void {
        $sptree = $this->diagram_box(10, 'B1', 500000, 2000000, 2600000, 1200000, '442980', 'Step One')
            . $this->diagram_arrow(20, 3200000, 2200000, 800000, 600000)
            . $this->diagram_box(11, 'B2', 4200000, 2000000, 2600000, 1200000, '1F4E79', 'Step Two');
        $page = $this->build_slide($sptree);
        $this->assertStringContainsString('local-lessonimportpptx-diagram', $page->html);
        $this->assertStringContainsString('<svg', $page->html);
        $this->assertStringContainsString('<polygon', $page->html);
        $this->assertStringContainsString('fill="#442980"', $page->html);
        $this->assertStringContainsString('fill="#1F4E79"', $page->html);
        $this->assertStringContainsString('Step One', $page->html);
        $this->assertStringContainsString('Step Two', $page->html);
        // The labels live in the figure, not as separate paragraphs.
        $this->assertStringNotContainsString('<p>Step One</p>', $page->html);
    }

    /**
     * A single captioned box is not a diagram; it stays editable text.
     */
    public function test_single_box_is_not_a_diagram(): void {
        // A long label avoids the short-line title promotion, so it stays in the body.
        $label = 'This standalone callout box should remain ordinary editable paragraph text.';
        $sptree = $this->diagram_box(10, 'B1', 500000, 2000000, 2600000, 1200000, '442980', $label);
        $page = $this->build_slide($sptree);
        $this->assertStringNotContainsString('<svg', $page->html);
        $this->assertStringContainsString('<p>' . $label . '</p>', $page->html);
    }

    /**
     * Unlabelled shapes (arrows, empty outlines) are treated as annotations and
     * are not reconstructed into a diagram.
     */
    public function test_unlabelled_shapes_are_not_reconstructed(): void {
        $sptree = $this->diagram_arrow(20, 3200000, 2200000, 800000, 600000)
            . $this->diagram_arrow(21, 5200000, 2200000, 800000, 600000)
            . '<p:sp><p:nvSpPr><p:cNvPr id="22" name="O"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="1000000" y="1000000"/><a:ext cx="900000" cy="900000"/></a:xfrm>'
            . '<a:prstGeom prst="ellipse"><a:avLst/></a:prstGeom>'
            . '<a:ln><a:solidFill><a:srgbClr val="FF0000"/></a:solidFill></a:ln></p:spPr>'
            . '<p:txBody><a:bodyPr/><a:p/></p:txBody></p:sp>';
        $page = $this->build_slide($sptree);
        $this->assertStringNotContainsString('<svg', $page->html);
    }

    /**
     * A slide led by a large photo keeps its image and editable text; captioned
     * boxes on it are not promoted into a diagram figure.
     */
    public function test_photo_slide_suppresses_diagram(): void {
        $pic = '<p:pic><p:nvPicPr><p:cNvPr id="5" name="P"/><p:cNvPicPr/><p:nvPr/></p:nvPicPr>'
            . '<p:blipFill><a:blip r:embed="rId5"/></p:blipFill>'
            . '<p:spPr><a:xfrm><a:off x="400000" y="400000"/><a:ext cx="6000000" cy="4000000"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic>';
        $sptree = $pic
            . $this->diagram_box(10, 'B1', 7000000, 1000000, 2000000, 900000, '442980', 'Alpha')
            . $this->diagram_box(11, 'B2', 7000000, 2200000, 2000000, 900000, '1F4E79', 'Beta');
        $page = $this->build_slide(
            $sptree,
            ['rId5' => '../media/image1.png'],
            ['ppt/media/image1.png' => 'PNGDATA']
        );
        $this->assertStringNotContainsString('<svg', $page->html);
        $this->assertStringContainsString('@@PLUGINFILE@@/image1.png', $page->html);
        $this->assertStringContainsString('<p>Alpha</p>', $page->html);
    }

    /**
     * A shape whose fill is a theme scheme colour resolves to the Office default
     * palette when the deck carries no theme part.
     */
    public function test_scheme_colour_fill_resolves_to_default(): void {
        $box1 = '<p:sp><p:nvSpPr><p:cNvPr id="10" name="B1"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="500000" y="2000000"/><a:ext cx="2600000" cy="1200000"/></a:xfrm>'
            . '<a:prstGeom prst="roundRect"><a:avLst/></a:prstGeom>'
            . '<a:solidFill><a:schemeClr val="accent1"/></a:solidFill></p:spPr>'
            . '<p:txBody><a:bodyPr/><a:p><a:r><a:t>Node A</a:t></a:r></a:p></p:txBody></p:sp>';
        $sptree = $box1
            . $this->diagram_box(11, 'B2', 4200000, 2000000, 2600000, 1200000, '1F4E79', 'Node B');
        $page = $this->build_slide($sptree);
        // The accent1 slot resolves to #4472C4 in the Office default scheme.
        $this->assertStringContainsString('fill="#4472C4"', $page->html);
    }

    /**
     * A diagram label containing markup is escaped, not injected, into the SVG.
     */
    public function test_diagram_label_is_escaped(): void {
        $evil = htmlspecialchars('</tspan><script>alert(1)</script>', ENT_XML1);
        $sptree = $this->diagram_box(10, 'B1', 500000, 2000000, 2600000, 1200000, '442980', $evil)
            . $this->diagram_box(11, 'B2', 4200000, 2000000, 2600000, 1200000, '1F4E79', 'Node B');
        $page = $this->build_slide($sptree);
        $this->assertStringContainsString('<svg', $page->html);
        $this->assertStringNotContainsString('<script>', $page->html);
        $this->assertStringContainsString('&lt;script&gt;', $page->html);
    }

    /**
     * Short decision-node labels (Yes/No) still form and populate a diagram.
     */
    public function test_short_label_boxes_reconstruct(): void {
        $sptree = $this->diagram_box(10, 'B1', 500000, 2000000, 1500000, 900000, '6EA9DB', 'Yes')
            . $this->diagram_box(11, 'B2', 4200000, 2000000, 1500000, 900000, '6EA9DB', 'No');
        $page = $this->build_slide($sptree);
        $this->assertStringContainsString('<svg', $page->html);
        $this->assertStringContainsString('Yes', $page->html);
        $this->assertStringContainsString('No', $page->html);
    }

    /**
     * Builds a filled, captioned round-rectangle shape for diagram tests.
     *
     * @param int $id The shape id.
     * @param string $name The shape name.
     * @param int $x Left offset in EMU.
     * @param int $y Top offset in EMU.
     * @param int $cx Width in EMU.
     * @param int $cy Height in EMU.
     * @param string $fill Six-hex-digit fill colour (no hash).
     * @param string $text The box label.
     * @return string The p:sp XML.
     */
    private function diagram_box(int $id, string $name, int $x, int $y, int $cx, int $cy, string $fill, string $text): string {
        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="' . $name . '"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="roundRect"><a:avLst/></a:prstGeom>'
            . '<a:solidFill><a:srgbClr val="' . $fill . '"/></a:solidFill></p:spPr>'
            . '<p:txBody><a:bodyPr/><a:p><a:r><a:t>' . $text . '</a:t></a:r></a:p></p:txBody></p:sp>';
    }

    /**
     * Builds a right-pointing block-arrow shape for diagram tests.
     *
     * @param int $id The shape id.
     * @param int $x Left offset in EMU.
     * @param int $y Top offset in EMU.
     * @param int $cx Width in EMU.
     * @param int $cy Height in EMU.
     * @return string The p:sp XML.
     */
    private function diagram_arrow(int $id, int $x, int $y, int $cx, int $cy): string {
        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Arrow"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rightArrow"><a:avLst/></a:prstGeom>'
            . '<a:solidFill><a:srgbClr val="FF772E"/></a:solidFill></p:spPr>'
            . '<p:txBody><a:bodyPr/><a:p/></p:txBody></p:sp>';
    }

    /**
     * Builds a one-slide deck whose slide shape tree is the given XML, then parses
     * and builds that slide into a page object (no database needed).
     *
     * @param string $sptree The inner XML of the slide's p:spTree.
     * @param array $rels Optional slide relationships: id => Target (e.g. ['rId5' => '../media/image1.png']).
     * @param array $media Optional media parts: zip path => bytes.
     * @return \stdClass The built page object.
     */
    private function build_slide(string $sptree, array $rels = [], array $media = []): \stdClass {
        $nsp = package::NS_P;
        $nsa = package::NS_A;
        $nsr = package::NS_R;
        $nspr = package::NS_PR;

        $dir = make_request_directory();
        $path = $dir . '/one.pptx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString(
            '[Content_Types].xml',
            '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>'
        );
        $zip->addFromString(
            'ppt/presentation.xml',
            '<?xml version="1.0"?><p:presentation xmlns:p="' . $nsp . '" xmlns:r="' . $nsr . '">'
                . '<p:sldIdLst><p:sldId id="256" r:id="rId1"/></p:sldIdLst>'
                . '<p:sldSz cx="12192000" cy="6858000"/></p:presentation>'
        );
        $zip->addFromString(
            'ppt/_rels/presentation.xml.rels',
            '<?xml version="1.0"?><Relationships xmlns="' . $nspr . '">'
                . '<Relationship Id="rId1" Type="http://x/slide" Target="slides/slide1.xml"/></Relationships>'
        );
        $zip->addFromString(
            'ppt/slides/slide1.xml',
            '<?xml version="1.0"?><p:sld xmlns:p="' . $nsp . '" xmlns:a="' . $nsa . '" xmlns:r="' . $nsr . '">'
                . '<p:cSld><p:spTree>' . $sptree . '</p:spTree></p:cSld></p:sld>'
        );
        if ($rels !== []) {
            $entries = '';
            foreach ($rels as $id => $target) {
                $entries .= '<Relationship Id="' . $id . '" Type="http://x/image" Target="' . $target . '"/>';
            }
            $zip->addFromString(
                'ppt/slides/_rels/slide1.xml.rels',
                '<?xml version="1.0"?><Relationships xmlns="' . $nspr . '">' . $entries . '</Relationships>'
            );
        }
        foreach ($media as $mpath => $bytes) {
            $zip->addFromString($mpath, $bytes);
        }
        $zip->close();

        $package = new package($path);
        $builder = new html_builder('#442980');
        $paths = $package->get_slide_paths();
        $page = $builder->build((new slide($package, $paths[0]))->parse());
        $package->close();
        return $page;
    }

    /**
     * SmartArt text is recovered as a flat list; tables become HTML tables.
     */
    public function test_smartart_and_table(): void {
        $pages = $this->build_all();
        $this->assertStringContainsString(
            '<ul><li>Step A</li><li>Step B</li><li>Step C</li></ul>',
            $pages[3]->html
        );
        $this->assertStringContainsString('<table', $pages[4]->html);
        $this->assertStringContainsString('<th>Day</th>', $pages[4]->html);
        $this->assertStringContainsString('<td>Mon</td>', $pages[4]->html);
    }

    /**
     * A section divider is detected by geometry, styled with the slide's own colour.
     */
    public function test_section_detection(): void {
        $pages = $this->build_all();
        $section = $pages[5];
        $this->assertTrue($section->issection);
        $this->assertSame('Getting Started', $section->title);
        $this->assertStringContainsString('local-lessonimportpptx-plate', $section->html);
        $this->assertStringContainsString('background-color:#1f4e79', $section->html);
        $this->assertStringContainsString('SECTION ONE', $section->html);
        // Ordinary slides are not sections.
        $this->assertFalse($pages[6]->issection);
    }

    /**
     * A full import creates one linked content page per slide, each with a
     * Continue button, and saves images into mod_lesson's page_contents area.
     */
    public function test_full_import_into_lesson(): void {
        global $DB;
        $this->resetAfterTest();
        // The lesson generator prepares draft file areas, which needs a real user.
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id]);
        $context = \context_module::instance($lesson->cmid);
        $record = $DB->get_record('lesson', ['id' => $lesson->id], '*', MUST_EXIST);

        $file = $this->make_stored_file($record->id, $context);
        $importer = new importer($record, $context);
        $created = $importer->import($file);
        $this->assertSame(9, $created);

        $all = $DB->get_records('lesson_pages', ['lessonid' => $record->id]);
        $this->assertCount(9, $all);

        // Walk the page chain from the head: it is linear, complete and ordered.
        $ordered = [];
        $prev = 0;
        while (true) {
            $next = null;
            foreach ($all as $candidate) {
                if ((int) $candidate->prevpageid === $prev && !isset($ordered[$candidate->id])) {
                    $next = $candidate;
                    break;
                }
            }
            if ($next === null) {
                break;
            }
            $ordered[$next->id] = $next;
            $prev = (int) $next->id;
        }
        $pages = array_values($ordered);
        $this->assertCount(9, $pages);
        $this->assertSame(0, (int) $pages[8]->nextpageid);

        // Every page is a content page (branch table) titled from its slide.
        foreach ($pages as $page) {
            $this->assertSame(page_writer::QTYPE_CONTENT, (int) $page->qtype);
        }
        $this->assertSame('Presentation Title', $pages[0]->title);
        $this->assertSame('Overview', $pages[1]->title);
        $this->assertSame('Getting Started', $pages[5]->title);

        // Each page carries one Continue button jumping to the next page.
        foreach ($pages as $page) {
            $answers = array_values($DB->get_records('lesson_answers', ['pageid' => $page->id]));
            $this->assertCount(1, $answers);
            $this->assertSame(page_writer::JUMP_NEXTPAGE, (int) $answers[0]->jumpto);
        }

        // The image on slide 2 was saved into mod_lesson's page area and referenced.
        $this->assertStringContainsString('@@PLUGINFILE@@/image1.png', $pages[1]->contents);
        $fs = get_file_storage();
        $this->assertTrue($fs->file_exists(
            $context->id,
            'mod_lesson',
            'page_contents',
            $pages[1]->id,
            '/',
            'image1.png'
        ));
    }

    /**
     * Importing into a lesson that already has pages appends after the last one.
     */
    public function test_import_appends_after_existing_pages(): void {
        global $DB;
        $this->resetAfterTest();
        // The lesson generator prepares draft file areas, which needs a real user.
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id]);
        $context = \context_module::instance($lesson->cmid);
        $record = $DB->get_record('lesson', ['id' => $lesson->id], '*', MUST_EXIST);

        $existingid = page_writer::write($record, $context, 'Existing page', '<p>Already here.</p>', []);

        $file = $this->make_stored_file($record->id, $context);
        (new importer($record, $context))->import($file);

        $existing = $DB->get_record('lesson_pages', ['id' => $existingid], '*', MUST_EXIST);
        $this->assertSame(0, (int) $existing->prevpageid);
        $this->assertNotSame(0, (int) $existing->nextpageid);

        $first = $DB->get_record('lesson_pages', ['id' => $existing->nextpageid], '*', MUST_EXIST);
        $this->assertSame('Presentation Title', $first->title);
        $this->assertSame((int) $existingid, (int) $first->prevpageid);
    }

    /**
     * A non-zip upload is rejected with a clear error.
     */
    public function test_rejects_non_pptx(): void {
        $this->resetAfterTest();
        $dir = make_request_directory();
        $path = $dir . '/notreal.pptx';
        file_put_contents($path, 'this is not a zip');

        $this->expectException(\moodle_exception::class);
        new package($path);
    }

    /**
     * A Strict Open XML presentation is rejected rather than imported empty.
     */
    public function test_rejects_strict_ooxml(): void {
        $this->resetAfterTest();
        $dir = make_request_directory();
        $path = $dir . '/strict.pptx';
        $strictns = 'http://purl.oclc.org/ooxml/presentationml/main';

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString(
            '[Content_Types].xml',
            '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>'
        );
        $zip->addFromString(
            'ppt/presentation.xml',
            '<?xml version="1.0"?><p:presentation xmlns:p="' . $strictns . '"><p:sldIdLst/></p:presentation>'
        );
        $zip->close();

        $this->expectException(\moodle_exception::class);
        new package($path);
    }

    /**
     * The backend is chosen by file extension.
     */
    public function test_backend_type_detection(): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/local/lessonimportpptx/locallib.php');

        $this->assertSame('pdf', local_lessonimportpptx_type($this->make_named_file('doc.pdf', '%PDF-1.4')));
        $this->assertSame('pptx', local_lessonimportpptx_type($this->make_named_file('deck.pptx', 'x')));
    }

    /**
     * The importer chosen for an upload follows the file type and the import mode,
     * and the image mode degrades safely to the editable importer when the
     * LibreOffice render backend is unavailable.
     */
    public function test_importer_selection(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->dirroot . '/local/lessonimportpptx/locallib.php');

        $course = $this->getDataGenerator()->create_course();
        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id]);
        $context = \context_module::instance($lesson->cmid);
        $record = $DB->get_record('lesson', ['id' => $lesson->id], '*', MUST_EXIST);

        $pptx = $this->make_named_file('deck.pptx', 'x');
        $pdf = $this->make_named_file('doc.pdf', '%PDF-1.4');

        $this->assertInstanceOf(
            \local_lessonimportpptx\importer::class,
            local_lessonimportpptx_importer($pptx, $record, $context, ['importmode' => 'editable'])
        );
        $this->assertInstanceOf(
            \local_lessonimportpptx\pdf_importer::class,
            local_lessonimportpptx_importer($pdf, $record, $context, ['importmode' => 'editable'])
        );
        // With no LibreOffice on the test host, "images" mode falls back to editable.
        if (!\local_lessonimportpptx\office\renderer::is_available()) {
            $this->assertInstanceOf(
                \local_lessonimportpptx\importer::class,
                local_lessonimportpptx_importer($pptx, $record, $context, ['importmode' => 'images'])
            );
        }
    }

    /**
     * The image import backend creates one image content page per rendered slide.
     */
    public function test_office_importer_creates_image_pages(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id]);
        $context = \context_module::instance($lesson->cmid);
        $record = $DB->get_record('lesson', ['id' => $lesson->id], '*', MUST_EXIST);

        // A stub renderer stands in for LibreOffice + poppler, yielding fixed pages.
        $renderer = new class extends \local_lessonimportpptx\office\renderer {
            /**
             * Yields two fixed pages in place of a real LibreOffice render.
             *
             * @param \stored_file $pptx The (ignored) uploaded presentation.
             * @param int $maxdim The (ignored) maximum image dimension.
             * @return \Generator Yields [slidenumber, filename, bytes] arrays.
             */
            public function render_pages(\stored_file $pptx, int $maxdim): \Generator {
                yield [1, 'page-1.png', 'PNGONE'];
                yield [2, 'page-2.png', 'PNGTWO'];
            }
        };
        $file = $this->make_named_file('deck.pptx', 'x', $record->id, $context);
        $importer = new \local_lessonimportpptx\office_importer($record, $context, ['imagemaxdim' => 0], $renderer);
        $this->assertSame(2, $importer->import($file));

        $pages = array_values($DB->get_records('lesson_pages', ['lessonid' => $record->id], 'id ASC'));
        $this->assertCount(2, $pages);
        $this->assertStringContainsString('@@PLUGINFILE@@/page-1.png', $pages[0]->contents);
        $fs = get_file_storage();
        $this->assertTrue($fs->file_exists(
            $context->id,
            'mod_lesson',
            'page_contents',
            $pages[0]->id,
            '/',
            'page-1.png'
        ));
    }

    /**
     * The image backend's raw slide counter counts slide parts from the archive,
     * without invoking the editable parser.
     */
    public function test_office_count_slides(): void {
        $this->resetAfterTest();
        $this->assertSame(9, \local_lessonimportpptx\office_importer::count_slides($this->make_stored_file()));
    }

    /**
     * Image pages are titled from each slide's own title, falling back to
     * "Slide N" for a slide with no title placeholder.
     */
    public function test_office_importer_titles_from_slides(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id]);
        $context = \context_module::instance($lesson->cmid);
        $record = $DB->get_record('lesson', ['id' => $lesson->id], '*', MUST_EXIST);

        // Render four fixed pages; the titles come from the real fixture deck.
        $renderer = new class extends \local_lessonimportpptx\office\renderer {
            /**
             * Yields four fixed pages in place of a real LibreOffice render.
             *
             * @param \stored_file $pptx The (ignored) uploaded presentation.
             * @param int $maxdim The (ignored) maximum image dimension.
             * @return \Generator Yields [slidenumber, filename, bytes] arrays.
             */
            public function render_pages(\stored_file $pptx, int $maxdim): \Generator {
                yield [1, 'page-1.png', 'A'];
                yield [2, 'page-2.png', 'B'];
                yield [3, 'page-3.png', 'C'];
                yield [9, 'page-9.png', 'D'];
            }
        };
        $file = $this->make_stored_file($record->id, $context);
        $importer = new \local_lessonimportpptx\office_importer($record, $context, ['imagemaxdim' => 0], $renderer);
        $this->assertSame(4, $importer->import($file));

        $pages = array_values($DB->get_records('lesson_pages', ['lessonid' => $record->id], 'id ASC'));
        $this->assertSame('Presentation Title', $pages[0]->title);
        $this->assertSame('Overview', $pages[1]->title);
        $this->assertSame('Clock', $pages[2]->title);
        // Slide 9 has no title placeholder, so it falls back to the numbered title.
        $this->assertSame(get_string('slidetitle', 'local_lessonimportpptx', 9), $pages[3]->title);
    }

    /**
     * When poppler is available, a PDF imports one image content page per page.
     */
    public function test_pdf_import(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        if (!\local_lessonimportpptx\pdf\renderer::is_available()) {
            $this->markTestSkipped('The poppler utilities (pdfinfo, pdftoppm) are not installed on this host.');
        }

        $course = $this->getDataGenerator()->create_course();
        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id]);
        $context = \context_module::instance($lesson->cmid);
        $record = $DB->get_record('lesson', ['id' => $lesson->id], '*', MUST_EXIST);

        $file = $this->make_named_file('doc.pdf', $this->make_pdf(3), $record->id, $context);
        $this->assertSame(3, \local_lessonimportpptx\pdf_importer::count_pages($file));

        $importer = new \local_lessonimportpptx\pdf_importer($record, $context, ['imagemaxdim' => 1000]);
        $this->assertSame(3, $importer->import($file));

        $pages = array_values($DB->get_records('lesson_pages', ['lessonid' => $record->id], 'id ASC'));
        $this->assertCount(3, $pages);
        $this->assertStringContainsString('@@PLUGINFILE@@/page-1.', $pages[0]->contents);
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_lesson', 'page_contents', $pages[0]->id, 'id', false);
        $this->assertNotEmpty($files);
    }

    /**
     * Builds a valid PDF with the given number of blank pages.
     *
     * @param int $pages The number of pages.
     * @return string The PDF bytes.
     */
    private function make_pdf(int $pages): string {
        $objs = [
            1 => '<</Type/Catalog/Pages 2 0 R>>',
        ];
        $kids = [];
        for ($i = 0; $i < $pages; $i++) {
            $kids[] = (3 + $i) . ' 0 R';
            $objs[3 + $i] = '<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>';
        }
        $objs[2] = '<</Type/Pages/Kids[' . implode(' ', $kids) . ']/Count ' . $pages . '>>';
        ksort($objs);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xrefpos = strlen($pdf);
        $size = count($objs) + 1;
        $pdf .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
        for ($n = 1; $n < $size; $n++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
        }
        $pdf .= "trailer\n<</Size " . $size . "/Root 1 0 R>>\nstartxref\n" . $xrefpos . "\n%%EOF";
        return $pdf;
    }

    /**
     * Stores a named file with the given content in the plugin's import area.
     *
     * @param string $filename The file name (its extension drives backend selection).
     * @param string $content The file bytes.
     * @param int|null $itemid Item id to store under.
     * @param \context|null $context Context to store in (defaults to system).
     * @return \stored_file
     */
    private function make_named_file(
        string $filename,
        string $content,
        ?int $itemid = null,
        ?\context $context = null
    ): \stored_file {
        $context = $context ?? \context_system::instance();
        $fs = get_file_storage();
        return $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'local_lessonimportpptx',
            'filearea' => 'import',
            'itemid' => $itemid ?? 1,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * Builds a stored_file from the fixture in the plugin's import area.
     *
     * @param int|null $lessonid Item id to store under (defaults to a constant).
     * @param \context|null $context Context to store in (defaults to system).
     * @return \stored_file
     */
    private function make_stored_file(?int $lessonid = null, ?\context $context = null): \stored_file {
        $context = $context ?? \context_system::instance();
        $fs = get_file_storage();
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'local_lessonimportpptx',
            'filearea' => 'import',
            'itemid' => $lessonid ?? 1,
            'filepath' => '/',
            'filename' => 'sample.pptx',
        ];
        // Remove any earlier copy so the test is repeatable within a run.
        $fs->delete_area_files($context->id, 'local_lessonimportpptx', 'import', $lessonid ?? 1);
        return $fs->create_file_from_pathname($filerecord, $this->fixture());
    }
}
