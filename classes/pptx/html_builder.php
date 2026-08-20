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
 * Turns ordered slide blocks into editable page HTML.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx\pptx;

/**
 * Assembles page HTML from parsed blocks: paragraphs and lists, responsive
 * image grids with caption pairing, tables/SmartArt, and section-divider heroes.
 *
 * Bootstrap grid classes (bundled with Moodle themes) do the layout so the
 * output inherits the site theme; only the section plate carries bespoke CSS.
 */
class html_builder {
    /** @var int A preceding text block this short (stripped) can be an image caption. */
    const CAPTION_MAX_CHARS = 12;

    /** @var int A single short line up to this length can be promoted to the page title. */
    const TITLE_FALLBACK_MAX_CHARS = 60;

    /** @var int Minimum horizontal gap (EMU, ~1 inch) between blocks for a genuine column split. */
    const COLUMN_GAP_EMU = 914400;

    /** @var int A lone image at least this wide on the slide (percent) is rendered to fill that width. */
    const FILL_WIDTH_PERCENT = 60;

    /** @var string Fallback plate colour when a slide's own fill cannot be read. */
    private string $defaultcolour;

    /** @var bool Whether runs of plain (non-diagram) images become Bootstrap card groups. */
    private bool $cardgroup;

    /** @var array Map of page filename => source media path in the package. */
    private array $images = [];

    /** @var int Point size forced on body text (0 keeps the slide's own sizes). */
    private int $bodysize;

    /** @var int Point size forced on text that sits beside an image (0 keeps the slide's own sizes). */
    private int $adjacentsize;

    /**
     * Constructor.
     *
     * @param string $defaultcolour Fallback section-plate colour (e.g. "#442980").
     * @param bool $cardgroup When true, a run of two or more plain images renders
     *                        as a Bootstrap card group (matching the tiny_bootstrap
     *                        plugin) instead of an image grid; a lone image is a
     *                        centred figure either way.
     * @param int $bodysize Point size to force on body text, or 0 to keep the slide's sizes.
     * @param int $adjacentsize Point size to force on text beside an image, or 0 to keep the slide's sizes.
     */
    public function __construct(string $defaultcolour, bool $cardgroup = false, int $bodysize = 0, int $adjacentsize = 0) {
        $this->defaultcolour = self::safe_colour($defaultcolour, '#442980');
        $this->cardgroup = $cardgroup;
        $this->bodysize = max(0, $bodysize);
        $this->adjacentsize = max(0, $adjacentsize);
    }

    /**
     * Builds a page from a parsed slide.
     *
     * @param \stdClass $parsed The result of {@see slide::parse()}.
     * @return \stdClass Object with properties:
     *                   - title (?string): page title, or null to use "Slide N";
     *                   - html (string): the page body HTML;
     *                   - issection (bool): whether this is a section divider;
     *                   - images (array<string,string>): filename => source media path.
     */
    public function build(\stdClass $parsed): \stdClass {
        $this->images = [];
        if ($parsed->section !== null) {
            return $this->build_section($parsed);
        }

        $body = $parsed->blocks;
        $title = $parsed->title;
        if ($title === null) {
            [$title, $body] = $this->promote_title(reading_order::sort($body));
        }
        return (object) [
            'title' => $title,
            'html' => $this->render_items($body),
            'issection' => ($parsed->section !== null),
            'images' => $this->images,
        ];
    }

    /**
     * Builds a section-divider page as a coloured hero plus content.
     *
     * Geometry-detected dividers carry a panel edge: text overlapping the panel
     * becomes the plate label. A divider detected only through the section-header
     * layout has no panel geometry, so its plate is labelled with the slide title
     * instead — either way the page renders as a styled section hero.
     *
     * @param \stdClass $parsed The parsed slide (section is non-null).
     * @return \stdClass The page object (see {@see html_builder::build()}).
     */
    private function build_section(\stdClass $parsed): \stdClass {
        $panelright = $parsed->section->panelright;

        // Text overlapping the plate is the section label; the rest is content.
        $overlay = [];
        $rest = [];
        foreach ($parsed->blocks as $b) {
            if ($panelright !== null && $b->type === block::TYPE_TEXT && $b->x < $panelright) {
                $overlay[] = $b;
            } else {
                $rest[] = $b;
            }
        }

        $lines = [];
        foreach (reading_order::sort($overlay) as $b) {
            foreach ($b->content as $para) {
                foreach (explode("\n", $para) as $line) {
                    $line = trim(strip_tags($line));
                    if ($line !== '') {
                        $lines[] = $line;
                    }
                }
            }
        }

        $title = $parsed->title;
        if ($title === null) {
            [$title, $rest] = $this->promote_title(reading_order::sort($rest));
        }

        // A layout-detected divider has no panel text: label the plate with the
        // section title so the hero still reads as a divider.
        if ($panelright === null && empty($lines)) {
            $label = $title ?? get_string('sectiondefault', 'local_lessonimportpptx');
            if (trim($label) !== '') {
                $lines[] = s($label);
            }
        }

        $lead = array_values(array_filter($rest, static function (block $b): bool {
            // Audio rides with the lede so a narrated section slide keeps its clip.
            return $b->type === block::TYPE_TEXT || $b->type === block::TYPE_HTML
                || $b->type === block::TYPE_AUDIO;
        }));
        $media = array_values(array_filter($rest, static function (block $b): bool {
            return $b->type === block::TYPE_IMAGE;
        }));

        $colour = self::safe_colour($parsed->section->colour ?? '', $this->defaultcolour);
        // A full-bleed section illustration now sits inside the col-md-9 lede column,
        // so its slide-relative width would be measured against that narrower box and
        // shrink again. Rebase such images to fill the column instead.
        foreach ($media as $m) {
            if ($m->widthpct >= self::FILL_WIDTH_PERCENT) {
                $m->widthpct = 100;
            }
        }
        // The lede text and the section illustration share one column so the
        // coloured plate beside them spans the full height of the text and image
        // together, not just the height of the text. Bootstrap grid: the plate in
        // a narrow col-md-3, the lede-and-media in col-md-9, stacking on phones.
        $content = $this->render_items($lead) . $this->render_items($media);
        if (!empty($lines)) {
            $plate = '<div class="col-12 col-md-3"><div class="local-lessonimportpptx-plate" style="background-color:'
                . $colour . ';">' . implode('<br>', $lines) . '</div></div>';
            $hero = '<div class="container-fluid local-lessonimportpptx-section"><div class="row">'
                . $plate . '<div class="col-12 col-md-9 local-lessonimportpptx-lede">' . $content . '</div></div></div>';
        } else {
            $hero = '<div class="local-lessonimportpptx-lede">' . $content . '</div>';
        }
        $html = trim($hero);

        return (object) [
            'title' => $title,
            'html' => $html,
            'issection' => true,
            'images' => $this->images,
        ];
    }

    /**
     * Renders a list of blocks to HTML.
     *
     * Blocks are grouped into the horizontal bands reading order already uses;
     * a band holding several side-by-side blocks becomes even Bootstrap columns,
     * so slides laid out in two or three columns (or text beside an image) keep
     * that arrangement. Consecutive image rows collapse into a responsive grid,
     * and a row of short lines directly above an equal-sized row of images is
     * paired as captions.
     *
     * @param block[] $blocks The blocks to render (any order).
     * @return string The rendered HTML.
     */
    private function render_items(array $blocks): string {
        $bands = $this->into_bands(reading_order::sort($blocks));
        $parts = [];
        $count = count($bands);
        $b = 0;
        while ($b < $count) {
            $band = $bands[$b];

            // A row of short lines directly above an equal row of images: captions.
            // Captions are body text (not beside an image), so honour the body size.
            $caps = $this->caption_texts($band);
            if ($caps !== null) {
                $caps = array_map(function (string $c): string {
                    return $this->sized($c, $this->bodysize);
                }, $caps);
            }
            if ($caps !== null && $b + 1 < $count) {
                $next = $this->image_refs($bands[$b + 1]);
                if ($next !== null && count($next) === count($caps)) {
                    $parts[] = $this->cardgroup
                        ? $this->render_card_group($next, $caps)
                        : $this->render_grid($next, $caps);
                    $b += 2;
                    continue;
                }
            }

            // One or more consecutive image-only rows become a single grid.
            $imgs = $this->image_refs($band);
            if ($imgs !== null) {
                $b2 = $b + 1;
                while ($b2 < $count && ($more = $this->image_refs($bands[$b2])) !== null) {
                    $imgs = array_merge($imgs, $more);
                    $b2++;
                }
                if (count($imgs) === 1) {
                    // A lone image is a centred, height-capped figure — not a
                    // one-item card group, which would sit at half width on desktop
                    // and let a tall picture dominate the page.
                    $parts[] = $this->render_figure($imgs[0], $band[0]->widthpct);
                } else if ($this->cardgroup) {
                    $parts[] = $this->render_card_group($imgs, null);
                } else {
                    $parts[] = $this->render_grid($imgs, null);
                }
                $b = $b2;
                continue;
            }

            // A single block fills the width; several side by side become columns.
            $parts[] = count($band) === 1
                ? $this->render_block($band[0], $this->bodysize)
                : $this->render_columns($band);
            $b++;
        }
        return implode("\n", $parts);
    }

    /**
     * Partitions blocks already in reading order into consecutive row bands.
     *
     * @param block[] $blocks Blocks in reading order.
     * @return array[] A list of bands, each a left-to-right block[].
     */
    private function into_bands(array $blocks): array {
        // Overlap grouping needs at least one real height to work with. A single
        // unsized block (e.g. a placeholder that inherits its size from the layout)
        // must not drop the whole slide onto the crude fixed-band fallback, which
        // would stack a picture and the text genuinely beside it into two rows.
        if ($this->any_height_known($blocks)) {
            return $this->into_rows_by_overlap($blocks);
        }
        $bands = [];
        $current = [];
        $lastband = null;
        foreach ($blocks as $b) {
            $band = intdiv($b->y, reading_order::ROW_BAND_EMU);
            if ($lastband !== null && $band !== $lastband && $current !== []) {
                $bands[] = $current;
                $current = [];
            }
            $current[] = $b;
            $lastband = $band;
        }
        if ($current !== []) {
            $bands[] = $current;
        }
        return $bands;
    }

    /**
     * Whether at least one block carries a positive height.
     *
     * Overlap grouping tolerates the odd unsized block (it lands in its own row),
     * so a single known height is enough to prefer it over the crude fallback.
     *
     * @param block[] $blocks The blocks to test.
     * @return bool True if any block has a known height.
     */
    private function any_height_known(array $blocks): bool {
        foreach ($blocks as $b) {
            if ($b->cy > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Groups blocks into rows by vertical overlap, each row left-to-right by x.
     *
     * Processing top-to-bottom, a block joins the open row when it overlaps that
     * row's vertical span by at least half of the shorter extent; otherwise it
     * starts a new row. A block beside a tall image thus shares its row, while a
     * block genuinely below it starts a fresh one.
     *
     * @param block[] $blocks The blocks to group.
     * @return array[] A list of rows, each a left-to-right block[].
     */
    private function into_rows_by_overlap(array $blocks): array {
        $sorted = $blocks;
        usort($sorted, static function (block $a, block $b): int {
            return [$a->y, $a->x] <=> [$b->y, $b->x];
        });
        $rows = [];
        $current = [];
        $top = 0;
        $bot = 0;
        foreach ($sorted as $b) {
            $btop = $b->y;
            $bbot = $b->y + $b->cy;
            if ($current === []) {
                $current = [$b];
                $top = $btop;
                $bot = $bbot;
                continue;
            }
            $overlap = min($bot, $bbot) - max($top, $btop);
            $shorter = max(1, min($bbot - $btop, $bot - $top));
            if ($overlap * 2 >= $shorter) {
                $current[] = $b;
                $top = min($top, $btop);
                $bot = max($bot, $bbot);
            } else {
                $rows[] = $this->sort_by_x($current);
                $current = [$b];
                $top = $btop;
                $bot = $bbot;
            }
        }
        if ($current !== []) {
            $rows[] = $this->sort_by_x($current);
        }
        return $rows;
    }

    /**
     * Orders a row's blocks left-to-right by horizontal offset.
     *
     * @param block[] $row The row's blocks.
     * @return block[] The blocks sorted by x.
     */
    private function sort_by_x(array $row): array {
        usort($row, static function (block $a, block $b): int {
            return $a->x <=> $b->x;
        });
        return $row;
    }

    /**
     * Orders blocks top-to-bottom by vertical offset (stable on ties).
     *
     * @param block[] $blocks The blocks.
     * @return block[] The blocks sorted by y.
     */
    private function sort_by_y(array $blocks): array {
        usort($blocks, static function (block $a, block $b): int {
            return $a->y <=> $b->y;
        });
        return $blocks;
    }

    /**
     * Returns the image references for a band if every block in it is an image.
     *
     * @param block[] $band The band's blocks.
     * @return string[]|null Registered @@PLUGINFILE@@ refs, or null if not all images.
     */
    private function image_refs(array $band): ?array {
        $refs = [];
        foreach ($band as $b) {
            if ($b->type !== block::TYPE_IMAGE) {
                return null;
            }
            $refs[] = $this->register_image($b->content);
        }
        return $refs;
    }

    /**
     * Returns caption strings if a band is entirely short, single-line text.
     *
     * @param block[] $band The band's blocks.
     * @return string[]|null Inline caption HTML per block, or null if unsuitable.
     */
    private function caption_texts(array $band): ?array {
        if (count($band) < 2) {
            return null;
        }
        $caps = [];
        foreach ($band as $b) {
            if ($b->type !== block::TYPE_TEXT || count($b->content) !== 1) {
                return null;
            }
            $inline = str_replace("\n", ' ', $b->content[0]);
            if (\core_text::strlen(trim(strip_tags($inline))) > self::CAPTION_MAX_CHARS) {
                return null;
            }
            $caps[] = $inline;
        }
        return $caps;
    }

    /**
     * Renders same-row blocks as columns, but only when they occupy genuinely
     * distinct horizontal regions; blocks sharing an x (stacked or overlaid, such
     * as a picture fill and its text) are stacked in reading order instead.
     *
     * @param block[] $band The band's blocks, left to right.
     * @return string The row HTML.
     */
    private function render_columns(array $band): string {
        $columns = $this->cluster_by_x($band);
        $columned = count($columns) >= 2 && count($columns) <= 4;
        // Text counts as "beside an image" only when the row genuinely splits into
        // columns that include an image. Text overlaid on or stacked with an image
        // (a single cluster) is body text, not adjacent, so it takes the body size.
        $textsize = ($columned && $this->band_has_image($band)) ? $this->adjacentsize : $this->bodysize;
        // One horizontal group, or too many to sit side by side cleanly: just
        // stack in reading order (top-to-bottom).
        if (!$columned) {
            $stack = '';
            foreach ($this->sort_by_y($band) as $b) {
                $stack .= $this->render_block($b, $textsize);
            }
            return $stack;
        }
        $col = 'col-12 col-md-' . intdiv(12, count($columns));
        $cells = '';
        foreach ($columns as $group) {
            $inner = '';
            // Within a column, keep the slide's top-to-bottom order: the row was
            // sorted left-to-right for clustering, which can reorder stacked
            // blocks that share a column.
            foreach ($this->sort_by_y($group) as $b) {
                $inner .= $this->render_cell($b, $textsize);
            }
            $cells .= '<div class="' . $col . '">' . $inner . '</div>';
        }
        return '<div class="row g-3 mb-3 local-lessonimportpptx-cols">' . $cells . '</div>';
    }

    /**
     * Whether a band contains an image block (marking its text as image-adjacent).
     *
     * @param block[] $band The band's blocks.
     * @return bool True when at least one block is an image.
     */
    private function band_has_image(array $band): bool {
        foreach ($band as $b) {
            if ($b->type === block::TYPE_IMAGE) {
                return true;
            }
        }
        return false;
    }

    /**
     * Groups a band's blocks into horizontal clusters (columns).
     *
     * A block opens a new column when it overlaps the current column's horizontal
     * span by less than half of the narrower width. A block that mostly overlaps
     * the column (e.g. a caption laid over a background picture) stays in it and
     * is stacked; a block whose bounding box only slightly overhangs the column
     * (a common case where a text box is drawn wider than its text and laps over
     * the picture beside it) still splits off as its own column.
     *
     * @param block[] $band The band's blocks, sorted left to right.
     * @return array[] A list of columns, each a block[] in reading order.
     */
    private function cluster_by_x(array $band): array {
        $clusters = [];
        $current = [];
        $left = null;
        $right = null;
        $colknown = true;
        foreach ($band as $b) {
            $known = $b->cx > 0;
            $bleft = $b->x;
            $bright = $known ? $b->x + $b->cx : $b->x + self::COLUMN_GAP_EMU;
            if ($current !== [] && $left !== null) {
                if ($known && $colknown) {
                    // Real widths both sides: split when the horizontal overlap is a
                    // minority of the narrower block, tolerating a small overhang.
                    $overlap = min($right, $bright) - max($left, $bleft);
                    $narrower = max(1, min($bright - $bleft, $right - $left));
                    $split = $overlap * 2 < $narrower;
                } else {
                    // An unknown width somewhere: the ratio would compare against a
                    // synthetic gap, so fall back to splitting only past the edge.
                    $split = $bleft >= $right;
                }
                if ($split) {
                    $clusters[] = $current;
                    $current = [];
                    $left = null;
                    $right = null;
                    $colknown = true;
                }
            }
            $current[] = $b;
            $left = $left === null ? $bleft : min($left, $bleft);
            $right = $right === null ? $bright : max($right, $bright);
            $colknown = $colknown && $known;
        }
        if ($current !== []) {
            $clusters[] = $current;
        }
        return $clusters;
    }

    /**
     * Renders a single block's inner HTML with no column or figure wrapper.
     *
     * @param block $b The block.
     * @param int $textsize Point size to force on body text, or 0 to keep the slide's sizes.
     * @return string The inner HTML.
     */
    private function render_cell(block $b, int $textsize = 0): string {
        if ($b->type === block::TYPE_IMAGE) {
            return '<img src="' . $this->register_image($b->content) . '" alt="" class="img-fluid">';
        }
        if ($b->type === block::TYPE_HTML) {
            return $b->content;
        }
        if ($b->type === block::TYPE_AUDIO) {
            return $this->render_audio($b->content);
        }
        if ($b->type === block::TYPE_TEXT) {
            return $this->text_html($b, $textsize);
        }
        return '';
    }

    /**
     * Renders an imported audio clip as a native HTML5 player.
     *
     * The player is left without an autoplay attribute (browsers strip or ignore
     * it in stored content); the view-page hook's helper starts playback on the
     * first user gesture instead. A class marks the element for that helper.
     *
     * @param string $mediapath The audio media path within the package.
     * @return string The audio-player HTML.
     */
    private function render_audio(string $mediapath): string {
        $ref = $this->register_audio($mediapath);
        $ext = strtolower((string) pathinfo($mediapath, PATHINFO_EXTENSION));
        $mimes = [
            'm4a' => 'audio/mp4',
            'mp4' => 'audio/mp4',
            'aac' => 'audio/aac',
            'mp3' => 'audio/mpeg',
            'oga' => 'audio/ogg',
            'ogg' => 'audio/ogg',
            'wav' => 'audio/wav',
        ];
        $typeattr = isset($mimes[$ext]) ? ' type="' . $mimes[$ext] . '"' : '';
        $fallback = s(get_string('audiounsupported', 'local_lessonimportpptx'));
        return '<audio class="local-lessonimportpptx-audio" controls preload="none">'
            . '<source src="' . $ref . '"' . $typeattr . '>' . $fallback . '</audio>';
    }

    /**
     * Renders one full-width block, constraining a lone image to a centred figure.
     *
     * @param block $b The block.
     * @param int $textsize Point size to force on body text, or 0 to keep the slide's sizes.
     * @return string The HTML.
     */
    private function render_block(block $b, int $textsize = 0): string {
        if ($b->type === block::TYPE_IMAGE) {
            return $this->render_figure($this->register_image($b->content), $b->widthpct);
        }
        return $this->render_cell($b, $textsize);
    }

    /**
     * Renders a text block. Paragraphs are split into runs of the same bullet
     * state: a run whose bullets are switched off becomes plain paragraphs, while
     * a bulleted run becomes a list that nests wherever the slide indented its
     * bullets — so an intro line above a list stays prose, and an outline keeps
     * its heading-and-sub-point structure instead of flattening to one flat list.
     *
     * @param block $b The text block.
     * @param int $textsize Point size to force on this text, or 0 to keep the slide's sizes.
     * @return string The rendered HTML.
     */
    private function text_html(block $b, int $textsize = 0): string {
        $paras = array_map(static function (string $p): string {
            return str_replace("\n", '<br>', $p);
        }, (array) $b->content);
        $count = count($paras);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $this->sized('<p>' . $paras[0] . '</p>', $textsize);
        }

        $html = '';
        $i = 0;
        while ($i < $count) {
            // An empty nobullets entry means "unknown", which reads as bulleted.
            $isprose = !empty($b->nobullets[$i]);
            $j = $i;
            while ($j < $count && !empty($b->nobullets[$j]) === $isprose) {
                $j++;
            }
            if ($isprose) {
                for ($k = $i; $k < $j; $k++) {
                    $html .= '<p>' . $paras[$k] . '</p>';
                }
            } else {
                $html .= $this->nested_list(
                    array_slice($paras, $i, $j - $i),
                    array_slice($b->levels, $i, $j - $i)
                );
            }
            $i = $j;
        }
        return $this->sized($html, $textsize);
    }

    /**
     * Forces a point size on a rendered text block, overriding the slide's own
     * sizes: the parser's inline font-size values are dropped and the block is
     * wrapped at the chosen size. Returns the html unchanged when size is 0.
     *
     * @param string $html The rendered text HTML.
     * @param int $size The point size to force, or 0 to leave the html as-is.
     * @return string The sized HTML.
     */
    private function sized(string $html, int $size): string {
        if ($size <= 0) {
            return $html;
        }
        // Unwrap only the font-size spans the parser itself generated, never
        // arbitrary slide text that happens to read "font-size: ..pt", then wrap
        // the whole block at the chosen size.
        $html = preg_replace('#<span style="font-size:[0-9.]+pt;">(.*?)</span>#s', '$1', $html);
        return '<div style="font-size:' . $size . 'pt;">' . $html . '</div>';
    }

    /**
     * Builds a (possibly nested) unordered list from paragraph HTML and the
     * per-paragraph indent levels PowerPoint recorded.
     *
     * Depths are normalised so the first item sits at the root and each item is
     * at most one level deeper than the previous: every nested <ul> is therefore
     * a child of a real <li>, and no level value (however large or out of order)
     * can produce malformed markup or a runaway loop.
     *
     * @param string[] $paras Paragraph HTML strings.
     * @param int[] $levels Indent level per paragraph, aligned to $paras.
     * @return string The <ul> HTML.
     */
    private function nested_list(array $paras, array $levels): string {
        $depths = [];
        $prev = 0;
        foreach ($paras as $i => $unused) {
            $level = max(0, (int) ($levels[$i] ?? 0));
            $depths[$i] = $i === 0 ? 0 : min($level, $prev + 1);
            $prev = $depths[$i];
        }

        $html = '';
        $depth = -1;
        foreach ($paras as $i => $p) {
            $target = $depths[$i];
            if ($target > $depth) {
                while ($depth < $target) {
                    $html .= '<ul>';
                    $depth++;
                }
            } else {
                $html .= '</li>';
                while ($depth > $target) {
                    $html .= '</ul></li>';
                    $depth--;
                }
            }
            $html .= '<li>' . $p;
        }
        while ($depth >= 0) {
            $html .= '</li></ul>';
            $depth--;
        }
        return $html;
    }

    /**
     * Renders a lone image as a centred figure, filling the width it had on the
     * slide when it was a full-bleed graphic and otherwise keeping its own size.
     *
     * @param string $ref The image @@PLUGINFILE@@ reference.
     * @param int $widthpct The image's on-slide width as a percent of the slide (0 when unknown).
     * @return string The figure HTML.
     */
    private function render_figure(string $ref, int $widthpct = 0): string {
        // A picture that spanned most of the slide is a full-bleed graphic (a title
        // or break slide); render it at the width it had on the slide so it fills
        // the page instead of sitting small beside empty space. Smaller pictures
        // keep their natural size, capped by the figure's own CSS.
        $style = $widthpct >= self::FILL_WIDTH_PERCENT ? ' style="width:' . $widthpct . '%"' : '';
        return '<div class="local-lessonimportpptx-figure"><img src="' . $ref . '" alt="" class="img-fluid"' . $style . '></div>';
    }

    /**
     * Renders a responsive Bootstrap grid of images with optional captions above.
     *
     * @param string[] $imgs Image src references (already @@PLUGINFILE@@ links).
     * @param string[]|null $caps Captions aligned to $imgs, or null for none.
     * @return string The grid HTML.
     */
    private function render_grid(array $imgs, ?array $caps): string {
        // Two images sit 50/50; three or more wrap after three across on large screens.
        $col = count($imgs) === 2 ? 'col-12 col-md-6' : 'col-12 col-md-6 col-lg-4';
        $cells = '';
        foreach ($imgs as $idx => $ref) {
            $cap = '';
            if ($caps !== null && isset($caps[$idx])) {
                $cap = '<div class="local-lessonimportpptx-cap">' . $caps[$idx] . '</div>';
            }
            $cells .= '<div class="' . $col . '">' . $cap
                . '<img src="' . $ref . '" alt="" class="img-fluid"></div>';
        }
        return '<div class="row g-3 local-lessonimportpptx-grid">' . $cells . '</div>';
    }

    /**
     * Renders a run of images as a Bootstrap 5 card group, matching the markup
     * of the tiny_bootstrap editor plugin: each image becomes a card with a
     * click-to-enlarge zoom modal, and a paired short caption becomes the card
     * text. An image with no caption is a card with just the picture.
     *
     * @param string[] $imgs Image @@PLUGINFILE@@ references.
     * @param string[]|null $caps Captions aligned to $imgs (inline HTML), or null.
     * @return string The card-group HTML (a row of cards followed by their modals).
     */
    private function render_card_group(array $imgs, ?array $caps): string {
        // Column counts mirror tiny_bootstrap: pairs by default, three across for
        // three cards, and four across (wrapping) for four or more.
        $count = count($imgs);
        $rowcols = 'row-cols-1 row-cols-md-2';
        if ($count >= 4) {
            $rowcols = 'row-cols-1 row-cols-sm-2 row-cols-lg-4';
        } else if ($count === 3) {
            $rowcols = 'row-cols-1 row-cols-md-3';
        }
        $enlarge = s(get_string('clicktoenlarge', 'local_lessonimportpptx'));
        $cards = [];
        $modals = [];
        foreach ($imgs as $idx => $ref) {
            $caption = ($caps !== null && isset($caps[$idx])) ? trim($caps[$idx]) : '';
            // A request-unique id keeps the trigger/modal pair distinct even when
            // several pages (each built separately) render on one screen — a
            // per-page counter would repeat and cross-wire the zoom modals.
            $uid = \html_writer::random_id('lessonImportCard');
            $body = $caption === ''
                ? ''
                : '<div class="card-body"><p class="card-text">' . $caption . '</p></div>';
            $cards[] = '<div class="col local-lessonimportpptx-card">'
                . '<div class="card h-100">'
                . '<a href="#" class="tiny-bs-card-img-link" data-bs-toggle="modal" '
                . 'data-bs-target="#' . $uid . '" title="' . $enlarge . '">'
                . '<img src="' . $ref . '" class="card-img-top tiny-bs-card-img" '
                . 'style="cursor:zoom-in;" alt="">'
                . '</a>' . $body
                . '</div></div>';
            $modals[] = $this->render_card_modal($uid, $ref, $caption);
        }
        $row = '<div class="row ' . $rowcols . ' g-4 local-lessonimportpptx-cardgroup">'
            . implode('', $cards) . '</div>';
        return $row . "\n" . implode("\n", $modals);
    }

    /**
     * Renders the click-to-enlarge zoom modal for one card image.
     *
     * The modal is a plain Bootstrap 5 modal with inline sizing so it works on a
     * lesson page (where the editor plugin's own CSS is not loaded); the theme's
     * bundled Bootstrap JavaScript drives the open/close behaviour.
     *
     * @param string $uid The modal's DOM id, shared with its trigger link.
     * @param string $ref The image @@PLUGINFILE@@ reference.
     * @param string $caption The caption inline HTML, or '' for none.
     * @return string The modal HTML.
     */
    private function render_card_modal(string $uid, string $ref, string $caption): string {
        $close = s(get_string('closebuttontitle'));
        $arialabel = $caption === '' ? '' : ' aria-label="' . s(self::plain_text($caption)) . '"';
        $cappara = $caption === ''
            ? ''
            : '<p class="mt-2 mb-0 text-muted">' . $caption . '</p>';
        // No static aria-hidden: a closed .modal is already display:none (hidden
        // from assistive tech), and Bootstrap toggles aria-hidden and focus itself
        // on show/hide. Hard-coding it leaves a focusable subtree marked hidden,
        // which browsers flag when focus lands inside the dialog.
        return '<div class="modal fade tiny-bootstrap-modal local-lessonimportpptx-cardmodal" id="'
            . $uid . '" tabindex="-1"' . $arialabel . '>'
            . '<div class="modal-dialog modal-xl modal-dialog-centered">'
            . '<div class="modal-content">'
            . '<div class="modal-header py-2">'
            . '<h4 class="modal-title"></h4>'
            . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="'
            . $close . '"></button>'
            . '</div>'
            . '<div class="modal-body p-2 text-center" '
            . 'style="display:flex;flex-direction:column;align-items:center;justify-content:center;">'
            . '<img src="' . $ref . '" alt="" style="width:100%;height:65vh;object-fit:contain;">'
            . $cappara
            . '</div></div></div></div>';
    }

    /**
     * Promotes a short leading line to the page title.
     *
     * Only the first block in reading order is considered: if the slide's leading
     * content is not a short single-line text box, no title is promoted, so an
     * image caption or footer further down cannot be pulled out of the body.
     *
     * @param block[] $blocks Blocks in reading order.
     * @return array The [title, remaining-blocks] pair.
     */
    private function promote_title(array $blocks): array {
        if (empty($blocks)) {
            return [null, $blocks];
        }
        $first = $blocks[0];
        if ($first->type === block::TYPE_TEXT && count($first->content) === 1) {
            $plain = self::plain_text($first->content[0]);
            if ($plain !== '' && \core_text::strlen($plain) <= self::TITLE_FALLBACK_MAX_CHARS) {
                array_shift($blocks);
                return [$plain, $blocks];
            }
        }
        return [null, $blocks];
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
        return $this->register_media($mediapath, $base);
    }

    /**
     * Registers an audio clip for saving and returns its @@PLUGINFILE@@ reference.
     *
     * @param string $mediapath Source media path within the package.
     * @return string The @@PLUGINFILE@@ link to embed in the HTML.
     */
    private function register_audio(string $mediapath): string {
        return $this->register_media($mediapath, self::media_basename($mediapath, 'audio'));
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

    /**
     * Records a media file under a unique file-area name and returns its link.
     *
     * @param string $mediapath Source media path within the package.
     * @param string $base The desired (sanitised) basename.
     * @return string The @@PLUGINFILE@@ link to embed in the HTML.
     */
    private function register_media(string $mediapath, string $base): string {
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
     * Reduces an escaped HTML fragment to trimmed, decoded plain text.
     *
     * @param string $html The fragment (may contain <br>, <strong>, entities).
     * @return string The plain-text equivalent.
     */
    private static function plain_text(string $html): string {
        $spaced = str_replace(['<br>', '<br/>', '<br />'], ' ', $html);
        return trim(html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Validates a colour string, returning a fallback if it is not #RRGGBB.
     *
     * @param string $colour The candidate colour.
     * @param string $fallback The value to use when $colour is invalid.
     * @return string A safe #RRGGBB colour.
     */
    private static function safe_colour(string $colour, string $fallback): string {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $colour) ? $colour : $fallback;
    }
}
