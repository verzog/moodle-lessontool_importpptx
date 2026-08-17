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
 * Renders PDF pages to images using poppler utilities.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx\pdf;

/**
 * Thin, injection-safe wrapper around the poppler utilities (pdfinfo, pdftoppm).
 *
 * This is the one place the plugin shells out to an external binary, and it is
 * strictly optional: the PDF backend is only offered when {@see self::is_available()}
 * returns true. The pure-PHP PowerPoint path never touches this class.
 */
class renderer {
    /** @var int Resolution, in DPI, at which pages are rasterised. */
    const DPI = 150;

    /** @var int Upper bound on pages to guard against abusive uploads. */
    const MAX_PAGES = 500;

    /** @var bool|null Cached availability result for this request. */
    private static ?bool $available = null;

    /**
     * Whether the poppler utilities needed for PDF import are usable.
     *
     * @return bool True if both pdfinfo and pdftoppm can be executed.
     */
    public static function is_available(): bool {
        if (self::$available !== null) {
            return self::$available;
        }
        self::$available = self::can_run(self::binary('pdfinfo')) && self::can_run(self::binary('pdftoppm'));
        return self::$available;
    }

    /**
     * Returns the number of pages in a PDF.
     *
     * @param \stored_file $pdf The uploaded PDF.
     * @return int The page count (0 if it could not be determined).
     */
    public static function count_pages(\stored_file $pdf): int {
        $path = self::stage($pdf);
        $result = self::run([self::binary('pdfinfo'), $path]);
        if ($result['code'] !== 0) {
            return 0;
        }
        if (preg_match('/^Pages:\s+(\d+)/m', $result['out'], $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    /**
     * Renders each page of a PDF to a web-friendly image.
     *
     * @param \stored_file $pdf The uploaded PDF.
     * @param int $maxdim Maximum image dimension in px (0 keeps the rendered size).
     * @return \Generator Yields [pagenumber, filename, bytes] arrays.
     * @throws \moodle_exception If rendering fails or the page count exceeds the cap.
     */
    public function render_pages(\stored_file $pdf, int $maxdim): \Generator {
        $count = self::count_pages($pdf);
        if ($count > self::MAX_PAGES) {
            throw new \moodle_exception('errortoomanypages', 'local_lessonimportpptx', '', (object) [
                'count' => $count,
                'max' => self::MAX_PAGES,
            ]);
        }

        $dir = make_request_directory();
        $path = $dir . '/import.pdf';
        $pdf->copy_content_to($path);
        yield from $this->render_path($path, $maxdim);
    }

    /**
     * Renders each page of a PDF already staged on disk to a web-friendly image.
     *
     * Shared by the PowerPoint-to-image (LibreOffice) backend, which converts a
     * .pptx to a PDF and then reuses this loop.
     *
     * @param string $path Absolute path to a PDF file on disk.
     * @param int $maxdim Maximum image dimension in px (0 keeps the rendered size).
     * @return \Generator Yields [pagenumber, filename, bytes] arrays.
     * @throws \moodle_exception If rendering fails.
     */
    public function render_path(string $path, int $maxdim): \Generator {
        $prefix = dirname($path) . '/page';

        $result = self::run([self::binary('pdftoppm'), '-png', '-r', (string) self::DPI, $path, $prefix]);
        if ($result['code'] !== 0) {
            throw new \moodle_exception('errorpdfrender', 'local_lessonimportpptx');
        }

        $pages = glob($prefix . '-*.png');
        // Page numbers are zero-padded in the filenames, so a plain string sort is numeric order.
        sort($pages, SORT_STRING);
        $number = 0;
        foreach ($pages as $file) {
            $number++;
            $bytes = file_get_contents($file);
            @unlink($file);
            if ($bytes === false || $bytes === '') {
                continue;
            }
            [$ext, $web] = \local_lessonimportpptx\image_helper::to_web($bytes, $maxdim);
            yield [$number, 'page-' . $number . '.' . $ext, $web];
        }
    }

    /**
     * Builds the path to a poppler binary, honouring the optional poppler directory.
     *
     * @param string $name The binary name (pdfinfo or pdftoppm).
     * @return string The command to run.
     */
    private static function binary(string $name): string {
        $dir = trim((string) get_config('local_lessonimportpptx', 'popplerpath'));
        return $dir === '' ? $name : rtrim($dir, '/') . '/' . $name;
    }

    /**
     * Copies a stored file to a local temporary path.
     *
     * @param \stored_file $file The stored file.
     * @return string Absolute path to the staged copy.
     */
    private static function stage(\stored_file $file): string {
        $dir = make_request_directory();
        $path = $dir . '/import.pdf';
        $file->copy_content_to($path);
        return $path;
    }

    /**
     * Whether a binary can be executed at all.
     *
     * @param string $binary The command to test.
     * @return bool True if it started and returned a version.
     */
    private static function can_run(string $binary): bool {
        $result = self::run([$binary, '-v']);
        // Poppler prints its version to stderr and may exit 0 or 99; a started
        // process with recognisable output is enough to consider it usable.
        return $result['started'] && (stripos($result['err'] . $result['out'], 'poppler') !== false
            || stripos($result['err'] . $result['out'], 'pdf') !== false);
    }

    /**
     * Runs a command with arguments passed as an array (no shell, so no injection).
     *
     * @param string[] $command The command and its arguments.
     * @return array The run result with started, code, out and err keys.
     */
    private static function run(array $command): array {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['started' => false, 'code' => -1, 'out' => '', 'err' => ''];
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        return ['started' => true, 'code' => $code, 'out' => (string) $out, 'err' => (string) $err];
    }
}
