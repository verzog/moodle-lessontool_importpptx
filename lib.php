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
 * Library of interface functions for the PowerPoint import tool for Lesson.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds the "Import PowerPoint" action to a lesson's settings navigation.
 *
 * The Lesson module has no subplugin type, so this local plugin hooks the
 * global settings-navigation callback and attaches its action to the module
 * settings branch whenever the current page belongs to a lesson.
 *
 * @param settings_navigation $settingsnav The settings navigation object.
 * @param context $context The current page context.
 * @return void
 */
function local_lessonimportpptx_extend_settings_navigation(
    settings_navigation $settingsnav,
    context $context
) {
    global $PAGE;

    // Read the course module directly: the page object exposes cm through a
    // magic getter, so probing it with empty()/isset() can report "not set"
    // even when a direct read returns the module. Assign first, then test the
    // local variable, so no magic-property semantics are involved.
    $cm = $PAGE->cm;
    if (!$cm || $cm->modname !== 'lesson') {
        return;
    }
    if (!has_capability('local/lessonimportpptx:import', $cm->context)) {
        return;
    }

    $modulesettings = $settingsnav->find('modulesettings', navigation_node::TYPE_SETTING);
    if (!$modulesettings) {
        return;
    }

    $url = new moodle_url('/local/lessonimportpptx/index.php', ['id' => $cm->id]);
    $modulesettings->add(
        get_string('importpptx', 'local_lessonimportpptx'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'lessonimportpptx'
    );
}
