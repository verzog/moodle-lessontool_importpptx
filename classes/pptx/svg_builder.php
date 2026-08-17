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
 * Reconstructs a cluster of vector shapes as inline SVG.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx\pptx;

/**
 * Renders {@see shape} objects to a self-contained, responsive inline SVG so
 * that shape diagrams (boxes, block arrows and connectors) survive import as a
 * scalable figure rather than being dropped. All EMU coordinates are converted
 * to CSS pixels so the markup uses ordinary values that browsers render cleanly.
 */
class svg_builder {
    /** @var int EMU per CSS pixel (96 dpi). */
    const EMU_PER_PX = 9525;

    /** @var float Padding around the shape cluster, in pixels. */
    const PADDING = 6.0;

    /** @var float Default corner radius for rounded rectangles, in pixels. */
    const CORNER_RADIUS = 10.0;

    /** @var float Smallest text size rendered, in pixels. */
    const MIN_FONT = 9.0;

    /** @var float Largest text size rendered, in pixels. */
    const MAX_FONT = 20.0;

    /** @var float Default stroke width, in pixels, when none is given. */
    const DEFAULT_STROKE = 1.3;

    /**
     * Builds an SVG figure from a set of shapes.
     *
     * @param shape[] $shapes The shapes to render (at least one).
     * @return string The SVG markup wrapped in a responsive figure, or '' if empty.
     */
    public static function build(array $shapes): string {
        if (empty($shapes)) {
            return '';
        }
        [$minx, $miny, $width, $height] = self::bounds($shapes);
        if ($width <= 0 || $height <= 0) {
            return '';
        }

        $body = '';
        foreach ($shapes as $s) {
            $body .= self::render_shape($s);
        }

        // Screen readers see the figure as one image; name it from the box labels
        // so the content the reconstruction absorbed is not lost to them.
        $label = self::accessible_label($shapes);
        $title = $label === '' ? '' : '<title>' . $label . '</title>';
        $aria = $label === '' ? '' : ' aria-label="' . $label . '"';

        $viewbox = self::num($minx) . ' ' . self::num($miny) . ' ' . self::num($width) . ' ' . self::num($height);
        return '<div class="local-lessonimportpptx-figure local-lessonimportpptx-diagram">'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . $viewbox . '" '
            . 'role="img"' . $aria . ' preserveAspectRatio="xMidYMid meet" '
            . 'style="width:100%;height:auto;max-width:' . (int) round($width) . 'px;">'
            . $title . $body . '</svg></div>';
    }

    /**
     * Builds an escaped accessible name for the diagram from its box labels.
     *
     * @param shape[] $shapes The shapes.
     * @return string The escaped label text, or '' when there is none.
     */
    private static function accessible_label(array $shapes): string {
        $parts = [];
        foreach ($shapes as $s) {
            $text = trim(preg_replace('/\s+/', ' ', str_replace("\n", ' ', $s->text)));
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        if (empty($parts)) {
            return '';
        }
        return htmlspecialchars('Diagram: ' . implode('; ', $parts), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Converts EMU to pixels.
     *
     * @param int $emu A length in EMU.
     * @return float The length in pixels.
     */
    private static function px(int $emu): float {
        return $emu / self::EMU_PER_PX;
    }

    /**
     * Formats a pixel value compactly (up to two decimals, no trailing zeros).
     *
     * @param float $value A pixel value.
     * @return string The formatted number.
     */
    private static function num(float $value): string {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * Computes the padded bounding box of all shapes, in pixels.
     *
     * @param shape[] $shapes The shapes.
     * @return float[] The [minx, miny, width, height] box.
     */
    private static function bounds(array $shapes): array {
        $minx = PHP_INT_MAX;
        $miny = PHP_INT_MAX;
        $maxx = PHP_INT_MIN;
        $maxy = PHP_INT_MIN;
        foreach ($shapes as $s) {
            $minx = min($minx, $s->x);
            $miny = min($miny, $s->y);
            $maxx = max($maxx, $s->right());
            $maxy = max($maxy, $s->bottom());
        }
        return [
            self::px($minx) - self::PADDING,
            self::px($miny) - self::PADDING,
            self::px($maxx - $minx) + 2 * self::PADDING,
            self::px($maxy - $miny) + 2 * self::PADDING,
        ];
    }

    /**
     * Renders one shape (its geometry, then any centred text).
     *
     * @param shape $s The shape.
     * @return string The SVG fragment.
     */
    private static function render_shape(shape $s): string {
        $geometry = self::geometry($s);
        $rot = '';
        if ($s->rotation % 360 !== 0) {
            $cx = self::num(self::px($s->x + intdiv($s->cx, 2)));
            $cy = self::num(self::px($s->y + intdiv($s->cy, 2)));
            $rot = ' transform="rotate(' . $s->rotation . ' ' . $cx . ' ' . $cy . ')"';
        }
        $text = self::text($s);
        if ($rot === '') {
            return $geometry . $text;
        }
        return '<g' . $rot . '>' . $geometry . $text . '</g>';
    }

    /**
     * Renders a shape's outline/fill geometry (no text).
     *
     * @param shape $s The shape.
     * @return string The SVG element(s) for the shape body.
     */
    private static function geometry(shape $s): string {
        $style = self::fill_stroke($s);
        $x = self::px($s->x);
        $y = self::px($s->y);
        $w = self::px($s->cx);
        $h = self::px($s->cy);
        switch ($s->kind) {
            case shape::KIND_ELLIPSE:
                return '<ellipse cx="' . self::num($x + $w / 2) . '" cy="' . self::num($y + $h / 2) . '" '
                    . 'rx="' . self::num($w / 2) . '" ry="' . self::num($h / 2) . '" ' . $style . '/>';
            case shape::KIND_LINE:
                return self::line($s);
            case shape::KIND_ARROW:
                return '<polygon points="' . self::arrow_points($s) . '" ' . $style . '/>';
            case shape::KIND_ROUNDRECT:
                $r = self::num(min(self::CORNER_RADIUS, min($w, $h) / 4));
                return '<rect x="' . self::num($x) . '" y="' . self::num($y) . '" '
                    . 'width="' . self::num($w) . '" height="' . self::num($h) . '" '
                    . 'rx="' . $r . '" ry="' . $r . '" ' . $style . '/>';
            default:
                return '<rect x="' . self::num($x) . '" y="' . self::num($y) . '" '
                    . 'width="' . self::num($w) . '" height="' . self::num($h) . '" ' . $style . '/>';
        }
    }

    /**
     * Builds the fill and stroke attributes for a shape.
     *
     * @param shape $s The shape.
     * @return string The SVG presentation attributes.
     */
    private static function fill_stroke(shape $s): string {
        $fill = $s->fill !== null ? 'fill="' . $s->fill . '"' : 'fill="none"';
        if ($s->line !== null) {
            return $fill . ' stroke="' . $s->line . '" stroke-width="' . self::num(self::stroke($s)) . '"';
        }
        return $fill;
    }

    /**
     * Returns a shape's stroke width in pixels, defaulting when unset.
     *
     * @param shape $s The shape.
     * @return float The stroke width in pixels.
     */
    private static function stroke(shape $s): float {
        return $s->linewidth > 0 ? max(0.75, self::px($s->linewidth)) : self::DEFAULT_STROKE;
    }

    /**
     * Renders a straight connector, honouring horizontal/vertical flips.
     *
     * @param shape $s The line shape.
     * @return string The SVG line element.
     */
    private static function line(shape $s): string {
        $x1 = self::px($s->x);
        $y1 = self::px($s->y);
        $x2 = self::px($s->right());
        $y2 = self::px($s->bottom());
        if ($s->fliph) {
            [$x1, $x2] = [$x2, $x1];
        }
        if ($s->flipv) {
            [$y1, $y2] = [$y2, $y1];
        }
        $colour = $s->line ?? '#000000';
        return '<line x1="' . self::num($x1) . '" y1="' . self::num($y1) . '" '
            . 'x2="' . self::num($x2) . '" y2="' . self::num($y2) . '" '
            . 'stroke="' . $colour . '" stroke-width="' . self::num(self::stroke($s)) . '" stroke-linecap="round"/>';
    }

    /**
     * Computes the polygon points for a block arrow in its bounding box.
     *
     * Uses PowerPoint's default proportions: the shaft is half the cross-axis
     * and the head spans the remaining half. The flip flags fold into direction
     * so a horizontally flipped right arrow is drawn pointing left.
     *
     * @param shape $s The arrow shape.
     * @return string A space-separated "x,y" point list, in pixels.
     */
    private static function arrow_points(shape $s): string {
        $dir = $s->arrow;
        if ($s->fliph && ($dir === 'right' || $dir === 'left')) {
            $dir = $dir === 'right' ? 'left' : 'right';
        }
        if ($s->flipv && ($dir === 'up' || $dir === 'down')) {
            $dir = $dir === 'up' ? 'down' : 'up';
        }
        $x = self::px($s->x);
        $y = self::px($s->y);
        $w = self::px($s->cx);
        $h = self::px($s->cy);

        if ($dir === 'right' || $dir === 'left') {
            $shaft = $h / 4;
            $headlen = min($w, $h);
            $pts = [
                [$x, $y + $shaft], [$x + $w - $headlen, $y + $shaft],
                [$x + $w - $headlen, $y], [$x + $w, $y + $h / 2],
                [$x + $w - $headlen, $y + $h], [$x + $w - $headlen, $y + $h - $shaft],
                [$x, $y + $h - $shaft],
            ];
            if ($dir === 'left') {
                $pts = self::mirror($pts, 0, $x + $w / 2);
            }
        } else {
            $shaft = $w / 4;
            $headlen = min($h, $w);
            $pts = [
                [$x + $shaft, $y + $h], [$x + $shaft, $y + $headlen],
                [$x, $y + $headlen], [$x + $w / 2, $y],
                [$x + $w, $y + $headlen], [$x + $w - $shaft, $y + $headlen],
                [$x + $w - $shaft, $y + $h],
            ];
            if ($dir === 'down') {
                $pts = self::mirror($pts, 1, $y + $h / 2);
            }
        }
        $out = [];
        foreach ($pts as [$px, $py]) {
            $out[] = self::num($px) . ',' . self::num($py);
        }
        return implode(' ', $out);
    }

    /**
     * Mirrors points across a horizontal or vertical axis.
     *
     * @param array $pts The [x, y] points (pixels).
     * @param int $axisindex 0 to mirror x about a vertical axis, 1 for y.
     * @param float $axis The axis coordinate.
     * @return array The mirrored points.
     */
    private static function mirror(array $pts, int $axisindex, float $axis): array {
        foreach ($pts as &$p) {
            $p[$axisindex] = 2 * $axis - $p[$axisindex];
        }
        return $pts;
    }

    /**
     * Renders a shape's centred text, wrapped to the shape width.
     *
     * @param shape $s The shape (its text is already HTML-escaped).
     * @return string The SVG text element, or '' when the shape has no text.
     */
    private static function text(shape $s): string {
        if (trim($s->text) === '' || $s->kind === shape::KIND_LINE) {
            return '';
        }
        $w = self::px($s->cx);
        $h = self::px($s->cy);
        $font = max(self::MIN_FONT, min(self::MAX_FONT, $h / 5));
        $colour = $s->textcolour ?? self::contrast($s->fill);
        $lines = self::wrap($s->text, $w, $font);
        $cx = self::px($s->x) + $w / 2;
        $lineheight = $font * 1.2;
        $start = self::px($s->y) + $h / 2 - (count($lines) * $lineheight) / 2 + $font * 0.85;

        $tspans = '';
        foreach ($lines as $i => $line) {
            $ly = $start + $i * $lineheight;
            // The label is plain text; escape it here, at the point it enters markup,
            // so a caption containing "<", "&" or crafted tags cannot inject SVG/HTML.
            $escaped = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
            $tspans .= '<tspan x="' . self::num($cx) . '" y="' . self::num($ly) . '">' . $escaped . '</tspan>';
        }
        return '<text text-anchor="middle" font-family="sans-serif" '
            . 'font-size="' . self::num($font) . '" fill="' . $colour . '">' . $tspans . '</text>';
    }

    /**
     * Wraps plain text into lines that fit the given width.
     *
     * Width is estimated from the font size (an average glyph is ~0.55 em), which
     * is enough to keep labels inside their box without a font metrics library.
     *
     * @param string $text The plain, unescaped text (may contain "\n" hard breaks).
     * @param float $boxwidth The shape width in pixels.
     * @param float $font The font size in pixels.
     * @return string[] The wrapped lines (still plain, unescaped).
     */
    private static function wrap(string $text, float $boxwidth, float $font): array {
        $maxchars = max(1, (int) (($boxwidth * 0.92) / ($font * 0.55)));
        $lines = [];
        foreach (explode("\n", $text) as $para) {
            $words = preg_split('/\s+/', trim($para));
            $current = '';
            foreach ($words as $word) {
                $candidate = $current === '' ? $word : $current . ' ' . $word;
                if (\core_text::strlen($candidate) > $maxchars && $current !== '') {
                    $lines[] = $current;
                    $current = $word;
                } else {
                    $current = $candidate;
                }
            }
            if ($current !== '') {
                $lines[] = $current;
            }
        }
        return $lines === [] ? [''] : $lines;
    }

    /**
     * Chooses black or white text for legibility against a fill colour.
     *
     * @param string|null $fill The fill colour as #RRGGBB, or null.
     * @return string "#000000" or "#ffffff".
     */
    private static function contrast(?string $fill): string {
        if ($fill === null || !preg_match('/^#([0-9a-fA-F]{6})$/', $fill, $m)) {
            return '#000000';
        }
        $r = hexdec(substr($m[1], 0, 2));
        $g = hexdec(substr($m[1], 2, 2));
        $b = hexdec(substr($m[1], 4, 2));
        // Rec. 601 luma: light fills get dark text and vice versa.
        $luma = 0.299 * $r + 0.587 * $g + 0.114 * $b;
        return $luma > 150 ? '#000000' : '#ffffff';
    }
}
