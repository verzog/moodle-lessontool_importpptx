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
 * Selects the render backend to use for faithful-image import.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx\office;

/**
 * Central place that decides which {@see render_backend} the plugin should use:
 * the external render service when it is configured, otherwise the local
 * LibreOffice renderer when its binaries are present.
 */
class backend_factory {
    /**
     * Whether any render backend is available for faithful-image import.
     *
     * @return bool True when the cloud service is configured or LibreOffice is present.
     */
    public static function available(): bool {
        return cloud_renderer::is_configured() || renderer::is_available();
    }

    /**
     * Returns the preferred render backend, or null when none is available.
     *
     * @return render_backend|null The backend to use, or null.
     */
    public static function make(): ?render_backend {
        if (cloud_renderer::is_configured()) {
            return cloud_renderer::from_config();
        }
        if (renderer::is_available()) {
            return new renderer();
        }
        return null;
    }
}
