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
            $created = 0;

            $transaction = $DB->start_delegated_transaction();
            try {
                foreach ($renderer->render_pages($pptx, $this->imagemaxdim) as [$page, $filename, $bytes]) {
                    $title = get_string('slidetitle', 'local_lessonimportpptx', $page);
                    $html = '<img src="@@PLUGINFILE@@/' . $filename . '" alt="" class="img-fluid">';
                    // Stage the rendered image so the writer's single (pathname based)
                    // code path applies and only one page is held in memory.
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
