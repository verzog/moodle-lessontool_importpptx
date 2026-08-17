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
 * Value object describing one vector shape recovered from a slide.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx\pptx;

/**
 * A drawn shape (box, ellipse, block arrow or connector) with the geometry,
 * fill, outline and centred text needed to reconstruct it as SVG. Positions and
 * sizes are in EMU, matching the slide coordinate space.
 */
class shape {
    /** @var string Rectangle / rounded rectangle / plaque and similar box geometries. */
    const KIND_RECT = 'rect';

    /** @var string Rounded rectangle (drawn with a corner radius). */
    const KIND_ROUNDRECT = 'roundrect';

    /** @var string Ellipse or oval. */
    const KIND_ELLIPSE = 'ellipse';

    /** @var string A block arrow; {@see shape::$arrow} gives the direction. */
    const KIND_ARROW = 'arrow';

    /** @var string A straight connector or line. */
    const KIND_LINE = 'line';

    /** @var string The shape kind (one of the KIND_* constants). */
    public string $kind;

    /** @var int Left offset in EMU. */
    public int $x;

    /** @var int Top offset in EMU. */
    public int $y;

    /** @var int Width in EMU. */
    public int $cx;

    /** @var int Height in EMU. */
    public int $cy;

    /** @var int Clockwise rotation in degrees (0-359). */
    public int $rotation = 0;

    /** @var bool Whether the shape is flipped horizontally. */
    public bool $fliph = false;

    /** @var bool Whether the shape is flipped vertically. */
    public bool $flipv = false;

    /** @var string|null Fill colour as #RRGGBB, or null for no fill. */
    public ?string $fill = null;

    /** @var string|null Outline colour as #RRGGBB, or null for no visible outline. */
    public ?string $line = null;

    /** @var int Outline width in EMU (0 when no explicit width is set). */
    public int $linewidth = 0;

    /** @var string Direction for KIND_ARROW: one of right,left,up,down. */
    public string $arrow = 'right';

    /** @var string Centred plain text (already HTML-escaped), or '' for none. */
    public string $text = '';

    /** @var string|null Text colour as #RRGGBB, or null to auto-contrast the fill. */
    public ?string $textcolour = null;

    /**
     * Constructor.
     *
     * @param string $kind One of the KIND_* constants.
     * @param int $x Left offset in EMU.
     * @param int $y Top offset in EMU.
     * @param int $cx Width in EMU.
     * @param int $cy Height in EMU.
     */
    public function __construct(string $kind, int $x, int $y, int $cx, int $cy) {
        $this->kind = $kind;
        $this->x = $x;
        $this->y = $y;
        $this->cx = $cx;
        $this->cy = $cy;
    }

    /**
     * The right edge of the shape in EMU.
     *
     * @return int
     */
    public function right(): int {
        return $this->x + $this->cx;
    }

    /**
     * The bottom edge of the shape in EMU.
     *
     * @return int
     */
    public function bottom(): int {
        return $this->y + $this->cy;
    }
}
