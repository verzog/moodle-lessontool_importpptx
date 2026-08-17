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
 * Writes a single lesson content page and its images into mod_lesson.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx;

/**
 * Appends one content page (branch table) to the end of a lesson's page chain,
 * gives it a single "Continue" button that jumps to the next page, stores its
 * images in mod_lesson's page_contents file area, and fires the page-created
 * event. Shared by the PowerPoint and PDF backends.
 *
 * Lesson pages form a doubly-linked list (prevpageid/nextpageid), so the writer
 * maintains those links directly rather than tracking a page number.
 */
class page_writer {
    /**
     * @var int Content-page question type (LESSON_PAGE_BRANCHTABLE). The core
     * constant lives in mod/lesson/pagetypes/branchtable.php, which is not
     * loaded outside lesson's own pages; the stored value is stable.
     */
    const QTYPE_CONTENT = 20;

    /** @var int Jump meaning "next page" (LESSON_NEXTPAGE); ends the lesson on the last page. */
    const JUMP_NEXTPAGE = -1;

    /**
     * Writes one content page at the end of the lesson and returns its id.
     *
     * @param \stdClass $lesson The target lesson record.
     * @param \context_module $context The lesson's module context.
     * @param string $title The page title (plain text; truncated to 255 chars).
     * @param string $html The page body HTML (with @@PLUGINFILE@@ references).
     * @param array $imagepaths Map of filename to absolute path of image bytes on disk.
     * @return int The new lesson page id.
     */
    public static function write(
        \stdClass $lesson,
        \context_module $context,
        string $title,
        string $html,
        array $imagepaths
    ): int {
        global $DB;

        $now = time();

        // Append after the current tail of the page chain (nextpageid = 0).
        $lastid = (int) $DB->get_field(
            'lesson_pages',
            'MAX(id)',
            ['lessonid' => $lesson->id, 'nextpageid' => 0]
        );

        $page = (object) [
            'lessonid' => $lesson->id,
            'prevpageid' => $lastid,
            'nextpageid' => 0,
            'qtype' => self::QTYPE_CONTENT,
            'qoption' => 0,
            'layout' => 1,
            'display' => 1,
            'timecreated' => $now,
            'timemodified' => 0,
            'title' => \core_text::substr($title, 0, 255),
            'contents' => $html,
            'contentsformat' => FORMAT_HTML,
        ];
        $page->id = $DB->insert_record('lesson_pages', $page);
        if ($lastid) {
            $DB->set_field('lesson_pages', 'nextpageid', $page->id, ['id' => $lastid]);
        }

        // A content page's answers are its navigation buttons: one "Continue"
        // that jumps to the next page (or ends the lesson on the last page).
        $DB->insert_record('lesson_answers', (object) [
            'lessonid' => $lesson->id,
            'pageid' => $page->id,
            'jumpto' => self::JUMP_NEXTPAGE,
            'grade' => 0,
            'score' => 0,
            'flags' => 0,
            'timecreated' => $now,
            'timemodified' => 0,
            'answer' => get_string('continuebutton', 'local_lessonimportpptx'),
            'answerformat' => FORMAT_MOODLE,
        ]);

        $fs = get_file_storage();
        foreach ($imagepaths as $filename => $path) {
            if ($fs->file_exists($context->id, 'mod_lesson', 'page_contents', $page->id, '/', $filename)) {
                continue;
            }
            $fs->create_file_from_pathname([
                'contextid' => $context->id,
                'component' => 'mod_lesson',
                'filearea' => 'page_contents',
                'itemid' => $page->id,
                'filepath' => '/',
                'filename' => $filename,
            ], $path);
        }

        $event = \mod_lesson\event\page_created::create([
            'context' => $context,
            'objectid' => $page->id,
            'other' => [
                'pagetype' => get_string('branchtable', 'lesson'),
            ],
        ]);
        $event->add_record_snapshot('lesson_pages', $page);
        $event->trigger();

        return $page->id;
    }
}
