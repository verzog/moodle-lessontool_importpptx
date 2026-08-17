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
 * Imports a presentation into a lesson as one rendered image per slide.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx;

use local_lessonimportpptx\office\renderer;

/**
 * "Whole deck as images" backend: renders every slide to a faithful image with
 * LibreOffice (via {@see renderer}) and creates one image content page per slide,
 * in order. Use this when a deck's slides must look exactly as in PowerPoint and
 * editable text is not required.
 */
class office_importer {
    /** @var \stdClass The target lesson record. */
    private \stdClass $lesson;

    /** @var \context_module The lesson's module context. */
    private \context_module $context;

    /** @var int Maximum image dimension in px (0 keeps the rendered size). */
    private int $imagemaxdim;

    /** @var renderer|null The render backend (injectable for testing). */
    private ?renderer $renderer;

    /**
     * Constructor.
     *
     * @param \stdClass $lesson The lesson activity record.
     * @param \context_module $context The lesson's module context.
     * @param array $options Import options ('imagemaxdim' int).
     * @param renderer|null $renderer The render backend, or null to build the default.
     */
    public function __construct(\stdClass $lesson, \context_module $context, array $options = [], ?renderer $renderer = null) {
        $this->lesson = $lesson;
        $this->context = $context;
        $this->imagemaxdim = (int) ($options['imagemaxdim'] ?? 1600);
        $this->renderer = $renderer;
    }

    /**
     * Counts the slides in a presentation for the image backend.
     *
     * The whole-deck image path does not use the transitional-OOXML parser, so a
     * deck that parser rejects (for example Strict Open XML) can still be
     * rendered by LibreOffice. Counting its slide parts straight from the archive
     * keeps the confirmation count and async threshold working for those decks,
     * instead of failing them at the counting step the editable path uses.
     *
     * @param \stored_file $pptx The uploaded presentation.
     * @return int The number of slide parts in the package.
     * @throws \moodle_exception If the file is not a readable .pptx package.
     */
    public static function count_slides(\stored_file $pptx): int {
        $dir = make_request_directory();
        $path = $dir . '/count.pptx';
        $pptx->copy_content_to($path);
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \moodle_exception('errornopptx', 'local_lessonimportpptx');
        }
        try {
            $count = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name !== false && preg_match('#^ppt/slides/slide\d+\.xml$#', $name)) {
                    $count++;
                }
            }
            return $count;
        } finally {
            $zip->close();
        }
    }

    /**
     * Imports the presentation, creating one image content page per slide.
     *
     * @param \stored_file $pptx The uploaded presentation.
     * @return int The number of pages created.
     */
    public function import(\stored_file $pptx): int {
        global $DB;

        // Same discipline as the other backends: the lock serialises concurrent
        // appends to the page chain, and the transaction makes the import atomic
        // so a failed run (or adhoc retry) never leaves or duplicates partial pages.
        $lock = page_writer::acquire_lock($this->lesson->id);
        try {
            $renderer = $this->renderer ?? new renderer();
            $stagedir = make_request_directory();

            // Render the whole deck to staged files BEFORE opening the transaction:
            // the LibreOffice conversion and rasterisation can run up to the
            // conversion timeout, and holding a delegated transaction open that
            // long trips idle-transaction limits on some databases. A staging
            // failure here aborts the whole import (so an adhoc retry can re-run
            // it cleanly) rather than silently committing a deck with a slide missing.
            $staged = [];
            foreach ($renderer->render_pages($pptx, $this->imagemaxdim) as [$page, $filename, $bytes]) {
                $file = $stagedir . '/' . $page;
                if (file_put_contents($file, $bytes) === false) {
                    throw new \moodle_exception('errorofficerender', 'local_lessonimportpptx');
                }
                $staged[] = [
                    'title' => get_string('slidetitle', 'local_lessonimportpptx', $page),
                    'filename' => $filename,
                    'path' => $file,
                ];
            }

            $created = 0;
            $transaction = $DB->start_delegated_transaction();
            try {
                foreach ($staged as $item) {
                    $html = '<img src="@@PLUGINFILE@@/' . $item['filename']
                        . '" alt="' . s($item['title']) . '" class="img-fluid">';
                    page_writer::write(
                        $this->lesson,
                        $this->context,
                        $item['title'],
                        $html,
                        [$item['filename'] => $item['path']]
                    );
                    $created++;
                }

                if ($created > 0) {
                    $DB->set_field('lesson', 'timemodified', time(), ['id' => $this->lesson->id]);
                }
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
            return $created;
        } finally {
            $lock->release();
        }
    }
}
