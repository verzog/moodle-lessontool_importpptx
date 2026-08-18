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
use local_lessonimportpptx\pptx\package;

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

    /** @var int Seconds to allow the (cold-start-prone) version probe before giving up. */
    const PROBE_TIMEOUT = 10;

    /** @var int Seconds a cached availability result is trusted before re-probing. */
    const AVAILABLE_TTL = 3600;

    /** @var bool|null Cached availability result for this request. */
    private static ?bool $available = null;

    /**
     * Whether the tools needed to render slides to images are usable.
     *
     * Probing means starting the soffice binary, whose first (cold) start can be
     * slow enough to trip a web-server gateway timeout. The result is therefore
     * cached across requests so the import page pays that cost at most once per
     * {@see self::AVAILABLE_TTL}, and the probe itself is bounded by
     * {@see self::PROBE_TIMEOUT} so even a cold start returns in good time. The
     * short TTL lets a freshly installed LibreOffice be picked up without a
     * manual cache purge.
     *
     * The cache is keyed per host: availability is a property of the binaries on
     * this node, but plugin config is shared site-wide, so a web node's result
     * must not be trusted by a cron worker (or vice versa) that may have a
     * different PATH or packages. Each node caches, and trusts, only its own probe.
     *
     * @return bool True if LibreOffice and poppler can both be executed.
     */
    public static function is_available(): bool {
        if (self::$available !== null) {
            return self::$available;
        }
        $hit = self::read_cache();
        if ($hit !== null) {
            self::$available = $hit;
            return self::$available;
        }
        // Serialise the refresh so a burst of cache-miss requests does not each
        // launch its own cold probe: the winner probes and stores the result and
        // the rest reuse it once the lock frees. A failed lock just probes anyway.
        $factory = \core\lock\lock_config::get_lock_factory('local_lessonimportpptx_office');
        // Scope the lock to this environment's cache key so only requests that
        // would share the resulting value serialise; nodes with different keys
        // (and thus different results) do not needlessly wait on each other.
        $lock = $factory->get_lock(self::cache_key('probe'), self::PROBE_TIMEOUT + 5);
        try {
            if ($lock && ($hit = self::read_cache()) !== null) {
                self::$available = $hit;
                return self::$available;
            }
            self::$available = self::can_run_soffice() && pdfrenderer::is_available();
            set_config(self::cache_key('officeavailable'), self::$available ? 1 : 0, 'local_lessonimportpptx');
            set_config(self::cache_key('officeavailablecheck'), time(), 'local_lessonimportpptx');
            return self::$available;
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }

    /**
     * Returns this host's cached availability if still fresh, else null.
     *
     * @return bool|null The cached result, or null when absent or past the TTL.
     */
    private static function read_cache(): ?bool {
        $cached = get_config('local_lessonimportpptx', self::cache_key('officeavailable'));
        $checked = (int) get_config('local_lessonimportpptx', self::cache_key('officeavailablecheck'));
        if ($cached !== false && (time() - $checked) < self::AVAILABLE_TTL) {
            return (bool) (int) $cached;
        }
        return null;
    }

    /**
     * Builds a per-environment config key so a probe cached by one runtime is not
     * read by another that resolves binaries differently.
     *
     * Availability depends on where soffice/poppler are found, so the key mixes in
     * the host name, PATH and the configured binary directories: a web (php-fpm)
     * and a cron runtime on the same host but with different PATHs therefore cache
     * independently rather than trusting each other's result.
     *
     * @param string $name The base config name.
     * @return string The name suffixed with a short digest of the resolution environment.
     */
    private static function cache_key(string $name): string {
        $signature = implode('|', [
            (string) php_uname('n'),
            (string) getenv('PATH'),
            (string) get_config('local_lessonimportpptx', 'libreofficepath'),
            (string) get_config('local_lessonimportpptx', 'popplerpath'),
        ]);
        return $name . '_' . substr(md5($signature), 0, 12);
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
        self::assert_archive_within_limits($source);

        $pdfpath = self::convert_to_pdf($source, $dir);
        if ($pdfpath === null) {
            throw new \moodle_exception('errorofficerender', 'local_lessonimportpptx');
        }
        yield from (new pdfrenderer())->render_path($pdfpath, $maxdim);
    }

    /**
     * Rejects archives whose declared uncompressed size could exhaust a worker.
     *
     * The editable parser enforces per-part and total inflation caps as it reads
     * each part, but the image path hands the whole archive straight to
     * LibreOffice, which would otherwise inflate it unchecked. Scanning the
     * central directory's declared sizes up front applies the same zip-bomb
     * guard before any conversion begins.
     *
     * @param string $path Absolute path to the .pptx on disk.
     * @return void
     * @throws \moodle_exception If any single part, or the total, exceeds the caps.
     */
    private static function assert_archive_within_limits(string $path): void {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \moodle_exception('errornopptx', 'local_lessonimportpptx');
        }
        try {
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $total += (int) $stat['size'];
                if ((int) $stat['size'] > package::MAX_PART_SIZE || $total > package::MAX_TOTAL_SIZE) {
                    throw new \moodle_exception('errortoolarge', 'local_lessonimportpptx');
                }
            }
        } finally {
            $zip->close();
        }
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
        // UserInstallation wants a file URL, not a bare path, so a Windows
        // drive path (C:\...) becomes file:///C:/... rather than file://C:\...
        $profile = self::path_to_url($dir . '/loprofile');
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
     * Converts a filesystem path to a file URL LibreOffice will accept.
     *
     * On POSIX the path is already absolute (/var/...), giving file:///var/...;
     * on Windows it normalises separators and the drive prefix (C:\dir) to the
     * file:///C:/dir form UserInstallation requires.
     *
     * @param string $path Absolute filesystem path.
     * @return string The equivalent file:// URL.
     */
    private static function path_to_url(string $path): string {
        return 'file://' . '/' . ltrim(str_replace('\\', '/', $path), '/');
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
        // Just "--version": it prints the version and exits without starting the
        // headless service, so it is the cheapest way to confirm soffice runs.
        $result = self::run([self::binary(), '--version'], self::PROBE_TIMEOUT);
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
        // A fresh HOME isolates the LibreOffice profile, but the env array
        // replaces the whole environment, so PATH must be carried over or
        // soffice cannot locate its own helper binaries (soffice.bin, oosplash).
        $env = [
            'HOME' => make_request_directory(),
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        ];
        $process = @proc_open($command, $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            return ['started' => false, 'code' => -1, 'out' => '', 'err' => ''];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $exitcode = -1;
        $deadline = time() + $timeout;
        do {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                // Once the child exits, proc_get_status reports the true exit
                // code and reaps it, so a later proc_close() commonly returns
                // -1. Keep the code observed here so a clean run is not read
                // as a failure.
                $exitcode = (int) $status['exitcode'];
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
        $closed = proc_close($process);
        $code = $exitcode !== -1 ? $exitcode : $closed;
        return ['started' => true, 'code' => $code, 'out' => $out, 'err' => $err];
    }
}
