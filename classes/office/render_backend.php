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
 * Contract for a backend that rasterises a presentation to page images.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx\office;

/**
 * A render backend turns an uploaded presentation into one raster image per
 * slide. It is the seam that lets the importer swap how faithful-image import
 * is produced without touching the import flow.
 *
 * The bundled {@see renderer} implements this by driving LibreOffice and
 * poppler on the local server. Alternative implementations (for example one
 * that posts the file to an external render service, or one that screenshots a
 * reconstructed HTML slide with a headless browser) can be substituted wherever
 * a renderer is used, because the importer consumes only this contract.
 */
interface render_backend {
    /**
     * Renders each slide of a presentation to an image.
     *
     * Yields one tuple per rendered page, in slide order:
     * [int $page, string $filename, string $bytes] — the 1-based page number,
     * a suggested file-area basename, and the raw image bytes.
     *
     * @param \stored_file $pptx The uploaded presentation file.
     * @param int $maxdim The longest edge of each rendered image, in pixels.
     * @param string $renderfont Font family to force before rendering, or '' to keep the deck's fonts.
     * @return \Generator Yields [int, string, string] page tuples.
     */
    public function render_pages(\stored_file $pptx, int $maxdim, string $renderfont = ''): \Generator;
}
