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
 * Durable staging area for an uploaded presentation awaiting import.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx;

/**
 * Stores the uploaded .pptx in the module context so that both the confirmation
 * step and a later background task can read it, independent of the user's
 * transient draft file area. Each upload is kept under its own item id (the
 * unique draft id) so concurrent uploads to the same lesson never overwrite one
 * another, and a confirmation always imports exactly the deck it previewed.
 */
class pending_file {
    /** @var string The file component. */
    const COMPONENT = 'local_lessonimportpptx';

    /** @var string The file area holding a pending upload. */
    const FILEAREA = 'import';

    /** @var string Session key under which completed inline imports are recorded. */
    const IMPORTED_SESSION_KEY = 'local_lessonimportpptx_imported';

    /**
     * Copies a draft-area upload into durable storage keyed by the draft id.
     *
     * @param int $draftid The submitted draft item id (also used as the storage item id).
     * @param \context_module $context The lesson's module context.
     * @return \stored_file|null The stored file, or null if the draft was empty.
     */
    public static function store(int $draftid, \context_module $context): ?\stored_file {
        global $USER;

        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        $drafts = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftid, 'id DESC', false);
        $draft = reset($drafts);
        if (!$draft) {
            return null;
        }

        self::delete($context, $draftid);
        $filerecord = [
            'contextid' => $context->id,
            'component' => self::COMPONENT,
            'filearea' => self::FILEAREA,
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => $draft->get_filename(),
        ];
        return $fs->create_file_from_storedfile($filerecord, $draft);
    }

    /**
     * Returns a staged upload by its item id, if present.
     *
     * @param \context_module $context The lesson's module context.
     * @param int $itemid The staged upload's item id (the draft id used at upload).
     * @return \stored_file|null The stored file, or null when none is staged.
     */
    public static function get(\context_module $context, int $itemid): ?\stored_file {
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, self::COMPONENT, self::FILEAREA, $itemid, 'id DESC', false);
        $file = reset($files);
        return $file ?: null;
    }

    /**
     * Deletes a staged upload by its item id.
     *
     * @param \context_module $context The lesson's module context.
     * @param int $itemid The staged upload's item id.
     * @return void
     */
    public static function delete(\context_module $context, int $itemid): void {
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, self::COMPONENT, self::FILEAREA, $itemid);
    }

    /**
     * Records that an upload's inline import completed, keyed by context and item id.
     *
     * A confirmation submitted twice imports on the first request and finds the
     * staging area empty on the second. This marker lets that second request tell
     * "already imported" (stay quiet, the first request reported success) from a
     * staging area cleared for another reason — cancelled elsewhere, or purged by
     * the cleanup task — which warrants a warning that nothing was imported.
     *
     * @param \context_module $context The lesson's module context.
     * @param int $itemid The staged upload's item id.
     * @return void
     */
    public static function mark_imported(\context_module $context, int $itemid): void {
        global $SESSION;
        $imported = $SESSION->{self::IMPORTED_SESSION_KEY} ?? [];
        if (!is_array($imported)) {
            $imported = [];
        }
        $imported[$context->id . ':' . $itemid] = true;
        $SESSION->{self::IMPORTED_SESSION_KEY} = $imported;
    }

    /**
     * Whether an upload's inline import was recorded as completed in this session.
     *
     * @param \context_module $context The lesson's module context.
     * @param int $itemid The staged upload's item id.
     * @return bool True when this upload has already been imported.
     */
    public static function was_imported(\context_module $context, int $itemid): bool {
        global $SESSION;
        $imported = $SESSION->{self::IMPORTED_SESSION_KEY} ?? [];
        return is_array($imported) && !empty($imported[$context->id . ':' . $itemid]);
    }
}
