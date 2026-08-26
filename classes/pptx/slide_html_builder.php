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
 * Builds a fixed-size "canvas" HTML rendering of a slide.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx\pptx;

/**
 * Canvas-mode counterpart to {@see html_builder}.
 *
 * Where html_builder reflows a slide's blocks into responsive, editable page
 * markup, this builder lays each block out at its true slide coordinates on a
 * fixed-size stage — the shape geometry (x, y, cx, cy, rotation) the parser
 * already records. The result is a standalone HTML document meant to be
 * rasterised to a page image by a headless browser, so a faithful-image render
 * can be produced from the plugin's own reconstruction instead of by driving
 * LibreOffice.
 *
 * This is scaffolding for that render path: it is deliberately not yet wired
 * into the import flow, and it does not attempt every PowerPoint visual effect.
 * It models what the parser models — positioned text, images and reconstructed
 * SVG — and leaves richer fidelity (gradients, shadows, exact bullet glyphs,
 * tables styling) as follow-up work.
 */
class slide_html_builder {
    /** @var int English Metric Units per CSS pixel (914400 EMU/inch ÷ 96 px/inch). */
    const EMU_PER_PX = 9525;

    /** @var int Default slide width in EMU (13.333 inches, a 16:9 stage). */
    const DEFAULT_SLIDE_WIDTH = 12192000;

    /** @var int Default slide height in EMU (7.5 inches, a 16:9 stage). */
    const DEFAULT_SLIDE_HEIGHT = 6858000;

    /** @var string CSS class prefix for the generated stage and shapes. */
    private string $prefix = 'local-lessonimportpptx';

    /** @var array<string,string> Registered images: file-area name => source media path. */
    private array $images = [];

    /** @var string Fallback background colour for the stage. */
    private string $background;

    /** @var int Slide width in EMU. */
    private int $slidewidth;

    /** @var int Slide height in EMU. */
    private int $slideheight;

    /**
     * @param string $background Stage background colour (CSS), e.g. '#ffffff'.
     * @param int $slidewidth Slide width in EMU (defaults to a 16:9 stage).
     * @param int $slideheight Slide height in EMU (defaults to a 16:9 stage).
     */
    public function __construct(
        string $background = '#ffffff',
        int $slidewidth = self::DEFAULT_SLIDE_WIDTH,
        int $slideheight = self::DEFAULT_SLIDE_HEIGHT
    ) {
        $this->background = $background;
        $this->slidewidth = $slidewidth > 0 ? $slidewidth : self::DEFAULT_SLIDE_WIDTH;
        $this->slideheight = $slideheight > 0 ? $slideheight : self::DEFAULT_SLIDE_HEIGHT;
    }

    /**
     * Builds the canvas HTML for one parsed slide.
     *
     * @param \stdClass $parsed The parsed slide (with a blocks array).
     * @return \stdClass An object with:
     *                   - html (string): the fixed-size stage markup;
     *                   - images (array<string,string>): filename => source media path.
     */
    public function build(\stdClass $parsed): \stdClass {
        $this->images = [];
        $width = intdiv($this->slidewidth, self::EMU_PER_PX);
        $height = intdiv($this->slideheight, self::EMU_PER_PX);

        $boxes = '';
        foreach ($parsed->blocks as $b) {
            $boxes .= $this->render_box($b);
        }

        $style = 'position:relative;width:' . $width . 'px;height:' . $height . 'px;'
            . 'overflow:hidden;background:' . $this->background . ';';
        $html = '<div class="' . $this->prefix . '-slide" style="' . $style . '">' . $boxes . '</div>';

        return (object) [
            'html' => $html,
            'images' => $this->images,
        ];
    }

    /**
     * Renders one block as an absolutely-positioned shape box.
     *
     * @param block $b The block to render.
     * @return string The shape HTML, or '' when the block has nothing to show.
     */
    private function render_box(block $b): string {
        $inner = $this->inner_html($b);
        if ($inner === '') {
            return '';
        }
        return '<div class="' . $this->prefix . '-shape" style="' . $this->position_style($b) . '">'
            . $inner . '</div>';
    }

    /**
     * Builds the absolute-position CSS for a block from its EMU geometry.
     *
     * @param block $b The block.
     * @return string The inline CSS (position, offsets, size, rotation).
     */
    private function position_style(block $b): string {
        $style = 'position:absolute;'
            . 'left:' . intdiv($b->x, self::EMU_PER_PX) . 'px;'
            . 'top:' . intdiv($b->y, self::EMU_PER_PX) . 'px;';
        if ($b->cx > 0) {
            $style .= 'width:' . intdiv($b->cx, self::EMU_PER_PX) . 'px;';
        }
        if ($b->cy > 0) {
            $style .= 'height:' . intdiv($b->cy, self::EMU_PER_PX) . 'px;';
        }
        if (!empty($b->rotation)) {
            $style .= 'transform:rotate(' . (int) $b->rotation . 'deg);';
        }
        return $style;
    }

    /**
     * Renders the inner content of a block by type.
     *
     * @param block $b The block.
     * @return string The inner HTML.
     */
    private function inner_html(block $b): string {
        if ($b->type === block::TYPE_IMAGE) {
            $ref = $this->register_image((string) $b->content);
            return '<img src="' . $ref . '" alt="" style="width:100%;height:100%;object-fit:contain;">';
        }
        if ($b->type === block::TYPE_HTML) {
            // Reconstructed SVG or table markup renders as-is.
            return (string) $b->content;
        }
        if ($b->type === block::TYPE_AUDIO) {
            // A static slide image cannot carry an audio player.
            return '';
        }
        return $this->text_html($b);
    }

    /**
     * Renders a text block's paragraphs. Kept intentionally simple for the
     * skeleton: each non-empty paragraph becomes a &lt;p&gt;, with soft line
     * breaks preserved. Bullet nesting and per-run sizing are follow-up work.
     *
     * @param block $b The text block.
     * @return string The paragraph HTML, or '' when the block is blank.
     */
    private function text_html(block $b): string {
        $paras = [];
        foreach ((array) $b->content as $para) {
            $para = str_replace("\n", '<br>', $para);
            if (trim(strip_tags($para)) !== '') {
                $paras[] = $para;
            }
        }
        if (empty($paras)) {
            return '';
        }
        return '<p>' . implode('</p><p>', $paras) . '</p>';
    }

    /**
     * Registers an image for saving and returns its @@PLUGINFILE@@ reference.
     *
     * @param string $mediapath Source media path within the package.
     * @return string The @@PLUGINFILE@@ link to embed in the HTML.
     */
    private function register_image(string $mediapath): string {
        // WMF/EMF are vector formats a browser cannot display; the importer
        // converts them to PNG, so reference the converted name here.
        $base = preg_replace('/\.(wmf|emf)$/i', '.png', self::media_basename($mediapath, 'image'));
        $existing = array_search($mediapath, $this->images, true);
        if ($existing !== false) {
            return '@@PLUGINFILE@@/' . $existing;
        }
        $name = $base;
        if (isset($this->images[$name])) {
            $dot = strrpos($base, '.');
            $stem = $dot === false ? $base : substr($base, 0, $dot);
            $ext = $dot === false ? '' : substr($base, $dot);
            $counter = 1;
            do {
                $name = $stem . '_' . $counter . $ext;
                $counter++;
            } while (isset($this->images[$name]));
        }
        $this->images[$name] = $mediapath;
        return '@@PLUGINFILE@@/' . $name;
    }

    /**
     * Sanitises a media path into a safe file-area basename.
     *
     * @param string $mediapath Source media path within the package.
     * @param string $fallback Name to use when the path has no usable basename.
     * @return string The sanitised basename.
     */
    private static function media_basename(string $mediapath, string $fallback): string {
        $base = preg_replace('/[^a-zA-Z0-9._-]+/', '_', basename($mediapath));
        return ($base === '' || $base === null) ? $fallback : $base;
    }
}
