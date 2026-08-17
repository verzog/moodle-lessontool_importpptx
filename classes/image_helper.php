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
 * Image conversion helpers used by the PDF backend.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx;

/**
 * GD-based helpers for turning rendered page images into lean, web-friendly files.
 *
 * GD is a bundled PHP extension, not an external binary, so these respect the
 * plugin's no-shell-out rule.
 */
class image_helper {
    /**
     * Converts raw image bytes to a down-scaled, web-friendly image.
     *
     * The result is WebP when GD supports it (much smaller for rendered pages),
     * otherwise JPEG, otherwise the original bytes unchanged. The longest edge is
     * capped at $maxdim unless $maxdim is 0.
     *
     * @param string $bytes The source image bytes (e.g. a rendered PNG page).
     * @param int $maxdim Maximum longest-edge size in px (0 keeps the source size).
     * @return array A [extension, bytes] pair; extension has no dot.
     */
    public static function to_web(string $bytes, int $maxdim): array {
        if (!function_exists('imagecreatefromstring')) {
            return ['png', $bytes];
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return ['png', $bytes];
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($maxdim > 0 && ($width > $maxdim || $height > $maxdim)) {
            $scale = $maxdim / max($width, $height);
            $resized = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            if ($resized !== false) {
                imagedestroy($image);
                $image = $resized;
            }
        }

        ob_start();
        if (function_exists('imagewebp') && (gd_info()['WebP Support'] ?? false)) {
            $ext = 'webp';
            imagewebp($image, null, 85);
        } else {
            $ext = 'jpg';
            imagejpeg($image, null, 85);
        }
        $out = ob_get_clean();
        imagedestroy($image);

        return ($out === false || $out === '') ? ['png', $bytes] : [$ext, $out];
    }
}
