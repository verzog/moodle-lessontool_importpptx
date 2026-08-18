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
 * Value object representing a single extracted slide element.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx\pptx;

/**
 * A positioned block of content collected from a slide's shape tree.
 *
 * The block carries the DrawingML offset (in EMU) so blocks can be re-ordered
 * into reading order regardless of the order they appear in the XML.
 */
class block {
    /** @var string A title placeholder. Content is a plain-text string. */
    const TYPE_TITLE = 'title';

    /** @var string A text box. Content is an array of paragraph HTML strings. */
    const TYPE_TEXT = 'text';

    /** @var string A picture. Content is the media file's path within the package. */
    const TYPE_IMAGE = 'image';

    /** @var string Pre-built HTML (table or SmartArt). Content is an HTML string. */
    const TYPE_HTML = 'html';

    /** @var string One of the TYPE_* constants. */
    public string $type;

    /** @var int Vertical offset in EMU (English Metric Units). */
    public int $y;

    /** @var int Horizontal offset in EMU. */
    public int $x;

    /** @var int Width in EMU, or 0 when unknown (e.g. a synthetic test block). */
    public int $cx = 0;

    /** @var int Height in EMU, or 0 when unknown. Enables overlap-based row grouping. */
    public int $cy = 0;

    /** @var string|string[] The block payload; shape depends on {@see block::$type}. */
    public $content;

    /**
     * @var int[] Indent level (0-based) for each paragraph of a TYPE_TEXT block,
     *            aligned to {@see block::$content}. Empty for non-text blocks.
     */
    public array $levels = [];

    /**
     * @var bool[] Whether each paragraph of a TYPE_TEXT block suppresses its bullet,
     *             aligned to {@see block::$content}. A true entry renders as prose.
     *             Empty means "unknown", treated as bulleted.
     */
    public array $nobullets = [];

    /**
     * Constructor.
     *
     * @param string $type One of the TYPE_* constants.
     * @param int $y Vertical offset in EMU.
     * @param int $x Horizontal offset in EMU.
     * @param string|string[] $content The block payload.
     */
    public function __construct(string $type, int $y, int $x, $content) {
        $this->type = $type;
        $this->y = $y;
        $this->x = $x;
        $this->content = $content;
    }
}
