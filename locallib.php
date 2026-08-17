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
    $count = local_lessonimportpptx_count($file);

    if ($count > $threshold) {
        $task = new \local_lessonimportpptx\task\import_task();
        $task->set_custom_data([
            'lessonid' => $lesson->id,
            'cmid' => $cm->id,
            'fileitemid' => $pendingid,
            'type' => local_lessonimportpptx_type($file),
            'imagemaxdim' => (int) ($options['imagemaxdim'] ?? 1600),
            'sectioncolour' => (string) ($options['sectioncolour'] ?? '#442980'),
        ]);
        $task->set_userid($USER->id);
        \core\task\manager::queue_adhoc_task($task);
        return (object) ['queued' => true, 'count' => $count];
    }

    if (local_lessonimportpptx_type($file) === 'pdf') {
        $importer = new \local_lessonimportpptx\pdf_importer($lesson, $context, $options);
    } else {
        $importer = new \local_lessonimportpptx\importer($lesson, $context, $options);
    }
    $created = $importer->import($file);
    \local_lessonimportpptx\pending_file::delete($context, $pendingid);
    return (object) ['queued' => false, 'count' => $created];
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
 * @param stored_file $file The uploaded file.
 * @return int The number of lesson pages the import will create.
 */
function local_lessonimportpptx_count(stored_file $file): int {
    if (local_lessonimportpptx_type($file) === 'pdf') {
        return \local_lessonimportpptx\pdf_importer::count_pages($file);
    }
    return \local_lessonimportpptx\importer::count_slides($file);
}
