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
 * Reading-order sorting for extracted slide blocks.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx\pptx;

/**
 * Orders blocks the way a reader scans a slide: top-to-bottom in rows, and
 * left-to-right within each row.
 */
class reading_order {
    /** @var int Row-grouping tolerance in EMU (~0.5 inch). */
    const ROW_BAND_EMU = 457200;

    /**
     * Returns the blocks sorted into reading order.
     *
     * A strict y-sort scrambles items on the same visual row when their offsets
     * differ by a few thousand EMU. Grouping y into half-inch bands first, then
     * sorting by x within the band, keeps multi-column rows left-to-right.
     *
     * @param block[] $blocks The blocks to order.
     * @return block[] A new, ordered array (input is not mutated).
     */
    public static function sort(array $blocks): array {
        $sorted = $blocks;
        usort($sorted, static function (block $a, block $b): int {
            $banda = intdiv($a->y, self::ROW_BAND_EMU);
            $bandb = intdiv($b->y, self::ROW_BAND_EMU);
            if ($banda !== $bandb) {
                return $banda <=> $bandb;
            }
            return $a->x <=> $b->x;
        });
        return $sorted;
    }
}
