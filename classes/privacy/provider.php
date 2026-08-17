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
 * Privacy provider for the PowerPoint import tool.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx\privacy;

/**
 * Privacy provider. This plugin stores no personal data of its own; the
 * lesson pages and files it creates belong to mod_lesson.
 */
class provider implements \core_privacy\local\metadata\null_provider {
    /**
     * Returns the language string explaining why this plugin stores no data.
     *
     * @return string The identifier of the reason string.
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
