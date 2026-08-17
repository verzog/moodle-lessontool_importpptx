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
 * Renders PowerPoint slides to images using LibreOffice and poppler.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx\office;

use local_lessonimportpptx\pdf\renderer as pdfrenderer;

/**
 * Optional "render as image" backend: converts a .pptx to a PDF with headless
 * LibreOffice, then reuses the poppler {@see pdfrenderer} to rasterise each page
 * to a web image — one page per slide, in order. This produces a pixel-faithful
 * copy of a slide (arrows, SmartArt, gradients and all) for content the pure-PHP
 * editable path cannot reproduce.
 *
 * It is strictly optional and gated: the image import modes are only offered when
 * {@see self::is_available()} is true, i.e. both LibreOffice and poppler are
 * usable. Everything is invoked with argument arrays (never a shell string), so
 * there is no command-injection surface.
 */
class renderer {
    /** @var int Seconds to allow a single LibreOffice conversion before giving up. */
    const CONVERT_TIMEOUT = 120;

    /** @var bool|null Cached availability result for this request. */
    private static ?bool $available = null;

    /**
     * Whether the tools needed to render slides to images are usable.
     *
     * @return bool True if LibreOffice and poppler can both be executed.
     */
    public static function is_available(): bool {
        if (self::$available !== null) {
            return self::$available;
        }
        self::$available = self::can_run_soffice() && pdfrenderer::is_available();
        return self::$available;
    }

    /**
     * Renders each slide of a presentation to a web-friendly image.
     *
     * @param \stored_file $pptx The uploaded presentation.
     * @param int $maxdim Maximum image dimension in px (0 keeps the rendered size).
     * @return \Generator Yields [slidenumber, filename, bytes] arrays.
     * @throws \moodle_exception If conversion or rendering fails.
     */
    public function render_pages(\stored_file $pptx, int $maxdim): \Generator {
        $dir = make_request_directory();
        $source = $dir . '/import.pptx';
        $pptx->copy_content_to($source);

        $pdfpath = self::convert_to_pdf($source, $dir);
        if ($pdfpath === null) {
            throw new \moodle_exception('errorofficerender', 'local_lessonimportpptx');
        }
        yield from (new pdfrenderer())->render_path($pdfpath, $maxdim);
    }

    /**
     * Converts a presentation on disk to a PDF using headless LibreOffice.
     *
     * @param string $source Absolute path to the .pptx file.
     * @param string $dir Working directory (also holds a private LibreOffice profile).
     * @return string|null Absolute path to the produced PDF, or null on failure.
     */
    private static function convert_to_pdf(string $source, string $dir): ?string {
        // A per-run user profile keeps concurrent conversions from clashing.
        $profile = 'file://' . $dir . '/loprofile';
        $result = self::run([
            self::binary(),
            '-env:UserInstallation=' . $profile,
            '--headless', '--nologo', '--nofirststartwizard',
            '--convert-to', 'pdf', '--outdir', $dir, $source,
        ], self::CONVERT_TIMEOUT);
        if (!$result['started'] || $result['code'] !== 0) {
            return null;
        }
        $pdf = preg_replace('/\.pptx$/i', '.pdf', $source);
        return is_file($pdf) ? $pdf : null;
    }

    /**
     * Builds the path to the LibreOffice binary, honouring an optional directory.
     *
     * @return string The command to run (soffice, optionally directory-qualified).
     */
    private static function binary(): string {
        $dir = trim((string) get_config('local_lessonimportpptx', 'libreofficepath'));
        return $dir === '' ? 'soffice' : rtrim($dir, '/') . '/soffice';
    }

    /**
     * Whether LibreOffice can be executed at all.
     *
     * @return bool True if soffice started and reported a version.
     */
    private static function can_run_soffice(): bool {
        $result = self::run([self::binary(), '--headless', '--version'], 30);
        return $result['started'] && stripos($result['out'] . $result['err'], 'libreoffice') !== false;
    }

    /**
     * Runs a command with arguments passed as an array (no shell, so no injection).
     *
     * @param string[] $command The command and its arguments.
     * @param int $timeout Seconds to wait before killing the process.
     * @return array The run result with started, code, out and err keys.
     */
    private static function run(array $command, int $timeout): array {
        if (!function_exists('proc_open')) {
            return ['started' => false, 'code' => -1, 'out' => '', 'err' => ''];
        }
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes, null, ['HOME' => make_request_directory()]);
        if (!is_resource($process)) {
            return ['started' => false, 'code' => -1, 'out' => '', 'err' => ''];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $deadline = time() + $timeout;
        do {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (time() > $deadline) {
                proc_terminate($process, 9);
                break;
            }
            usleep(100000);
        } while (true);
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        return ['started' => true, 'code' => $code, 'out' => $out, 'err' => $err];
    }
}
