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
 * Scheduled task that removes abandoned staged uploads.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx\task;

/**
 * Deletes staged uploads left behind when a user walks away from the
 * confirmation step without confirming or cancelling. Confirmed and cancelled
 * imports clean up after themselves; anything still staged after the retention
 * window is abandoned. A background import whose staged file is removed simply
 * aborts on its next retry, so a stuck task cannot pin an upload forever.
 */
class cleanup_task extends \core\task\scheduled_task {
    /** @var int How long a staged upload may sit unconfirmed before removal. */
    const RETENTION = 7 * DAYSECS;

    /**
     * Returns the human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskcleanup', 'local_lessonimportpptx');
    }

    /**
     * Deletes staged uploads older than the retention window.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $fs = get_file_storage();
        $cutoff = time() - self::RETENTION;
        $records = $DB->get_records_select(
            'files',
            "component = :component AND filearea = :filearea AND timecreated < :cutoff",
            [
                'component' => \local_lessonimportpptx\pending_file::COMPONENT,
                'filearea' => \local_lessonimportpptx\pending_file::FILEAREA,
                'cutoff' => $cutoff,
            ]
        );
        $count = 0;
        foreach ($records as $record) {
            $fs->get_file_instance($record)->delete();
            $count++;
        }
        if ($count > 0) {
            mtrace("local_lessonimportpptx: removed {$count} abandoned staged upload file(s).");
        }
    }
}
