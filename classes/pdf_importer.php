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
 * Imports a PDF into a lesson, one page per content page.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx;

use local_lessonimportpptx\pdf\renderer;

/**
 * Optional PDF backend: renders each page to an image (via poppler) and creates
 * one lesson content page per PDF page. A PDF carries no reliable text
 * structure, so pages are imported as images rather than editable HTML.
 */
class pdf_importer {
    /** @var \stdClass The target lesson record. */
    private \stdClass $lesson;

    /** @var \context_module The lesson's module context. */
    private \context_module $context;

    /** @var int Maximum image dimension in px (0 keeps the rendered size). */
    private int $imagemaxdim;

    /**
     * Constructor.
     *
     * @param \stdClass $lesson The lesson activity record.
     * @param \context_module $context The lesson's module context.
     * @param array $options Import options ('imagemaxdim' int).
     */
    public function __construct(\stdClass $lesson, \context_module $context, array $options = []) {
        $this->lesson = $lesson;
        $this->context = $context;
        $this->imagemaxdim = (int) ($options['imagemaxdim'] ?? 1600);
    }

    /**
     * Counts the pages in a PDF without importing it.
     *
     * @param \stored_file $pdf The uploaded PDF.
     * @return int The number of pages.
     */
    public static function count_pages(\stored_file $pdf): int {
        return renderer::count_pages($pdf);
    }

    /**
     * Imports the PDF, creating one content page per PDF page.
     *
     * @param \stored_file $pdf The uploaded PDF.
     * @return int The number of pages created.
     */
    public function import(\stored_file $pdf): int {
        global $DB;

        // Same discipline as the PowerPoint path: the lock serialises concurrent
        // appends to the page chain, and the transaction makes the import atomic
        // so a failed run (or adhoc retry) never leaves or duplicates partial pages.
        $lock = page_writer::acquire_lock($this->lesson->id);
        try {
            $renderer = new renderer();
            $stagedir = make_request_directory();
            $created = 0;

            $transaction = $DB->start_delegated_transaction();
            try {
                foreach ($renderer->render_pages($pdf, $this->imagemaxdim) as [$page, $filename, $bytes]) {
                    $title = get_string('pagetitle', 'local_lessonimportpptx', $page);
                    $html = '<img src="@@PLUGINFILE@@/' . $filename . '" alt="" class="img-fluid">';
                    // Stage the rendered image on disk so the writer's single (pathname
                    // based) code path applies and only one page is held in memory.
                    $staged = $stagedir . '/' . $page;
                    if (file_put_contents($staged, $bytes) === false) {
                        continue;
                    }
                    unset($bytes);
                    page_writer::write($this->lesson, $this->context, $title, $html, [$filename => $staged]);
                    @unlink($staged);
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
