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

namespace local_lessonimportpptx;

/**
 * Hook callbacks for local_lessonimportpptx.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Loads the audio-autoplay helper on lesson pages.
     *
     * Imported narration renders as a native <audio> player. Browsers block
     * sound until the user interacts with the page, and Moodle strips inline
     * scripts from stored content, so a small helper is attached from here: it
     * tries to play on load and otherwise starts on the first user gesture.
     *
     * @param \core\hook\output\before_standard_footer_html_generation $hook The footer hook.
     * @return void
     */
    public static function before_standard_footer_html_generation(
        \core\hook\output\before_standard_footer_html_generation $hook
    ): void {
        global $PAGE;

        if (!isset($PAGE->cm) || $PAGE->cm->modname !== 'lesson') {
            return;
        }

        // Inline AMD keeps the helper self-contained (no build step) and scoped to
        // the players this plugin emits, marked by their class.
        $js = <<<'JS'
require([], function() {
    var players = document.querySelectorAll('.local-lessonimportpptx-audio');
    if (!players.length) {
        return;
    }
    // Play only one clip so that several narrated chapters on one page (a
    // print-all view) or several clips never start together.
    var player = players[0];
    // Completion events that grant media activation across mouse, touch and
    // keyboard; pointerdown/touchstart can fire before activation is granted.
    var events = ['click', 'keydown', 'touchend'];
    var onfirst = function() {
        var attempt = player.play();
        if (attempt && typeof attempt.then === 'function') {
            attempt.then(function() {
                events.forEach(function(name) {
                    document.removeEventListener(name, onfirst, true);
                });
            }).catch(function() {
                return null;
            });
        }
    };
    var listen = function() {
        events.forEach(function(name) {
            document.addEventListener(name, onfirst, true);
        });
    };
    // Try immediately (sites with prior media engagement allow it); wait for a
    // gesture only if that is blocked, and stop only once playback has started.
    var initial = player.play();
    if (initial && typeof initial.then === 'function') {
        initial.catch(listen);
    } else {
        listen();
    }
});
JS;
        $PAGE->requires->js_amd_inline($js);
    }
}
