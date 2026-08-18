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
 * Version details for the PowerPoint import tool for the Lesson module.
 *
 * The Lesson module has no subplugin framework (there is no "lessontool"
 * plugin type in core Moodle), so this importer ships as a local plugin that
 * attaches itself to each lesson's settings navigation.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_lessonimportpptx';
$plugin->version   = 2026081804;
$plugin->requires  = 2025041400;
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.4.1';
$plugin->dependencies = [
    'mod_lesson' => ANY_VERSION,
];
