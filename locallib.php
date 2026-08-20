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
 * Orchestration helpers for the PowerPoint import tool for Lesson.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Imports a staged presentation, either inline or as a background task.
 *
 * Presentations larger than the configured threshold are queued so the web
 * request stays responsive; smaller ones run immediately. In both cases the
 * staged upload is cleaned up (inline here, in the task for async runs).
 *
 * @param stored_file $file The staged .pptx (see \local_lessonimportpptx\pending_file).
 * @param stdClass $lesson The target lesson record.
 * @param context_module $context The lesson's module context.
 * @param stdClass|cm_info $cm The lesson's course module.
 * @param int $pendingid The staged upload's item id (for cleanup and the async task).
 * @param array $options Import options ('imagemaxdim' int, 'sectioncolour' string).
 * @return stdClass Object with properties queued (bool) and count (int slides/pages).
 */
function local_lessonimportpptx_process(
    stored_file $file,
    stdClass $lesson,
    context_module $context,
    $cm,
    int $pendingid,
    array $options = []
): stdClass {
    global $USER;

    // Presentations above this many slides import in the background. The
    // threshold has no settings UI; override it via forced_plugin_settings or
    // the CLI if a site needs to.
    $threshold = get_config('local_lessonimportpptx', 'asyncthreshold');
    $threshold = ($threshold === false || $threshold === '') ? 30 : (int) $threshold;
    $count = local_lessonimportpptx_count($file, $options);

    if ($count > $threshold) {
        $task = new \local_lessonimportpptx\task\import_task();
        $task->set_custom_data([
            'lessonid' => $lesson->id,
            'cmid' => $cm->id,
            'fileitemid' => $pendingid,
            'type' => local_lessonimportpptx_type($file),
            'imagemaxdim' => (int) ($options['imagemaxdim'] ?? 1600),
            'sectioncolour' => (string) ($options['sectioncolour'] ?? '#442980'),
            'importmode' => (string) ($options['importmode'] ?? 'editable'),
            'cardgroup' => (int) !empty($options['cardgroup']),
            'bodysize' => (int) ($options['bodysize'] ?? 0),
            'adjacentsize' => (int) ($options['adjacentsize'] ?? 0),
            'smartartimages' => (int) !empty($options['smartartimages']),
        ]);
        $task->set_userid($USER->id);
        \core\task\manager::queue_adhoc_task($task);
        return (object) ['queued' => true, 'count' => $count];
    }

    $importer = local_lessonimportpptx_importer($file, $lesson, $context, $options);
    $created = $importer->import($file);
    \local_lessonimportpptx\pending_file::delete($context, $pendingid);
    return (object) ['queued' => false, 'count' => $created];
}

/**
 * Chooses the importer for an upload, honouring the requested import mode.
 *
 * A PDF always imports as page images. A PowerPoint imports as editable content
 * pages by default, or as one rendered image per slide when the "images" mode is
 * requested and the LibreOffice render backend is available.
 *
 * @param stored_file $file The staged upload.
 * @param stdClass $lesson The target lesson record.
 * @param context_module $context The lesson's module context.
 * @param array $options Import options (including 'importmode').
 * @return object An importer exposing import(stored_file): int.
 */
function local_lessonimportpptx_importer(stored_file $file, stdClass $lesson, context_module $context, array $options) {
    if (local_lessonimportpptx_type($file) === 'pdf') {
        return new \local_lessonimportpptx\pdf_importer($lesson, $context, $options);
    }
    $mode = (string) ($options['importmode'] ?? 'editable');
    if ($mode === 'images' && \local_lessonimportpptx\office\renderer::is_available()) {
        return new \local_lessonimportpptx\office_importer($lesson, $context, $options);
    }
    return new \local_lessonimportpptx\importer($lesson, $context, $options);
}

/**
 * Returns the backend type for an uploaded file, by extension.
 *
 * @param stored_file $file The uploaded file.
 * @return string 'pdf' for PDFs, otherwise 'pptx'.
 */
function local_lessonimportpptx_type(stored_file $file): string {
    $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
    return $ext === 'pdf' ? 'pdf' : 'pptx';
}

/**
 * Counts the units (slides or pages) an upload will produce, per backend.
 *
 * Counting mirrors the importer routing: a PDF is counted by page, an image-mode
 * PowerPoint by its raw slide parts (so a Strict OOXML deck bound for LibreOffice
 * is not blocked by the editable parser), and everything else by the editable
 * parser's slide count.
 *
 * @param stored_file $file The uploaded file.
 * @param array $options Import options (including 'importmode').
 * @return int The number of lesson pages the import will create.
 */
function local_lessonimportpptx_count(stored_file $file, array $options = []): int {
    if (local_lessonimportpptx_type($file) === 'pdf') {
        $count = \local_lessonimportpptx\pdf_importer::count_pages($file);
        // Enforce the renderer's page cap here, at confirmation time, so an
        // over-limit PDF is rejected with a clear message instead of being
        // queued as a background task that can only ever fail.
        $max = \local_lessonimportpptx\pdf\renderer::MAX_PAGES;
        if ($count > $max) {
            throw new \moodle_exception('errortoomanypages', 'local_lessonimportpptx', '', (object) [
                'count' => $count,
                'max' => $max,
            ]);
        }
        return $count;
    }
    $mode = (string) ($options['importmode'] ?? 'editable');
    if ($mode === 'images' && \local_lessonimportpptx\office\renderer::is_available()) {
        return \local_lessonimportpptx\office_importer::count_slides($file);
    }
    return \local_lessonimportpptx\importer::count_slides($file);
}
