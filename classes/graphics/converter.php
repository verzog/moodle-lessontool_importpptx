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
 * Converts vector images (WMF/EMF) to a web-renderable raster format.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx\graphics;

/**
 * Turns WMF/EMF vector images into PNG, which browsers can display.
 *
 * A WMF that merely wraps a bitmap is unpacked in pure PHP (no dependency). True
 * vector metafiles need an external renderer and are converted with whichever of
 * ImageMagick, LibreOffice or Inkscape is installed. When nothing can handle an
 * image the caller drops it rather than emit a broken reference.
 */
class converter {
    /** @var int[] WMF record functions that carry a device-independent bitmap. */
    private const DIB_RECORDS = [0x0F43, 0x0B41, 0x0940, 0x0D33, 0x0F41];

    /** @var string[]|null Cached list of external converter binaries that can run. */
    private static ?array $tools = null;

    /**
     * Whether an external WMF/EMF converter is installed.
     *
     * The pure-PHP bitmap path needs no external tool, so vector conversion may
     * still fail while this returns false; it reports only the external renderers.
     *
     * @return bool True if at least one external converter can be run.
     */
    public static function is_available(): bool {
        return self::tools() !== [];
    }

    /**
     * Converts WMF/EMF image bytes to PNG bytes.
     *
     * @param string $bytes The source vector image bytes.
     * @param string $ext The source extension ('wmf' or 'emf').
     * @return string|null The PNG bytes, or null if it could not be converted.
     */
    public static function to_png(string $bytes, string $ext): ?string {
        if ($bytes === '') {
            return null;
        }
        // A bitmap wrapped in a WMF can be unpacked without any external tool.
        if (strtolower($ext) === 'wmf') {
            $png = self::wmf_bitmap_to_png($bytes);
            if ($png !== null) {
                return $png;
            }
        }
        return self::external_to_png($bytes, $ext);
    }

    /**
     * Converts using each installed external renderer until one succeeds.
     *
     * @param string $bytes The source vector image bytes.
     * @param string $ext The source extension ('wmf' or 'emf').
     * @return string|null The PNG bytes, or null if no renderer produced output.
     */
    private static function external_to_png(string $bytes, string $ext): ?string {
        $ext = strtolower($ext) === 'emf' ? 'emf' : 'wmf';
        foreach (self::tools() as $tool) {
            // A fresh directory per attempt guarantees no stale output is mistaken
            // for this conversion's result.
            $dir = make_request_directory();
            $source = $dir . '/source.' . $ext;
            if (file_put_contents($source, $bytes) === false) {
                continue;
            }
            $out = $dir . '/source.png';
            if ($tool === 'soffice') {
                self::run([
                    'soffice', '--headless', '-env:UserInstallation=file://' . $dir . '/loprofile',
                    '--convert-to', 'png', '--outdir', $dir, $source,
                ]);
            } else if ($tool === 'inkscape') {
                self::run(['inkscape', $source, '--export-type=png', '--export-filename=' . $out]);
            } else {
                self::run([$tool, $source, $out]);
            }
            if (is_file($out) && filesize($out) > 0) {
                $png = file_get_contents($out);
                if ($png !== false && $png !== '') {
                    return $png;
                }
            }
        }
        return null;
    }

    /**
     * Unpacks a bitmap stored inside a WMF, in pure PHP, when the metafile is
     * essentially a single raster image rather than vector drawing commands.
     *
     * @param string $bytes The WMF bytes.
     * @return string|null The PNG bytes, or null if the WMF is not a bitmap wrapper.
     */
    private static function wmf_bitmap_to_png(string $bytes): ?string {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $len = strlen($bytes);
        $offset = 0;
        // Skip the optional 22-byte placeable header.
        if (substr($bytes, 0, 4) === "\xD7\xCD\xC6\x9A") {
            $offset = 22;
        }
        if ($offset + 18 > $len) {
            return null;
        }
        $type = unpack('v', substr($bytes, $offset, 2))[1];
        if ($type !== 1 && $type !== 2) {
            return null;
        }

        $pos = $offset + 18;
        $beststart = null;
        $bestbytes = 0;
        while ($pos + 6 <= $len) {
            $recwords = unpack('V', substr($bytes, $pos, 4))[1];
            $func = unpack('v', substr($bytes, $pos + 4, 2))[1];
            if ($recwords < 3) {
                break;
            }
            $recbytes = $recwords * 2;
            if ($pos + $recbytes > $len) {
                break;
            }
            if (in_array($func, self::DIB_RECORDS, true) && $recbytes > $bestbytes) {
                $bestbytes = $recbytes;
                $beststart = $pos;
            }
            $pos += $recbytes;
        }
        // Only treat the metafile as a bitmap wrapper when one DIB dominates it;
        // otherwise it is genuine vector art for the external renderer to handle.
        if ($beststart === null || $bestbytes < $len * 0.5) {
            return null;
        }

        $record = substr($bytes, $beststart + 6, $bestbytes - 6);
        $dib = self::find_dib($record);
        if ($dib === null) {
            return null;
        }
        return self::dib_to_png($dib);
    }

    /**
     * Locates a BITMAPINFOHEADER-based DIB within a WMF record's parameters.
     *
     * @param string $record The record bytes (after the size and function words).
     * @return string|null The DIB bytes from the header onward, or null if not found.
     */
    private static function find_dib(string $record): ?string {
        $len = strlen($record);
        for ($o = 0; $o + 40 <= $len; $o += 2) {
            if (unpack('V', substr($record, $o, 4))[1] !== 40) {
                continue;
            }
            $width = self::to_signed(unpack('V', substr($record, $o + 4, 4))[1]);
            $height = self::to_signed(unpack('V', substr($record, $o + 8, 4))[1]);
            $planes = unpack('v', substr($record, $o + 12, 2))[1];
            $bpp = unpack('v', substr($record, $o + 14, 2))[1];
            if (
                $planes === 1 && in_array($bpp, [1, 4, 8, 16, 24, 32], true)
                    && $width > 0 && $width <= 20000 && abs($height) > 0 && abs($height) <= 20000
            ) {
                return substr($record, $o);
            }
        }
        return null;
    }

    /**
     * Wraps a DIB in a BMP file header and rasterises it to PNG via GD.
     *
     * @param string $dib The DIB bytes (BITMAPINFOHEADER onward).
     * @return string|null The PNG bytes, or null if GD could not decode the bitmap.
     */
    private static function dib_to_png(string $dib): ?string {
        $bisize = unpack('V', substr($dib, 0, 4))[1];
        $bpp = unpack('v', substr($dib, 14, 2))[1];
        $compression = unpack('V', substr($dib, 16, 4))[1];
        $clrused = unpack('V', substr($dib, 32, 4))[1];

        $palette = 0;
        if ($bpp <= 8) {
            $palette = ($clrused > 0 ? $clrused : (1 << $bpp)) * 4;
        }
        // BI_BITFIELDS stores three colour masks between the header and the pixels.
        if ($compression === 3 && ($bpp === 16 || $bpp === 32)) {
            $palette += 12;
        }
        $offbits = 14 + $bisize + $palette;
        $header = 'BM' . pack('V', 14 + strlen($dib)) . pack('v', 0) . pack('v', 0) . pack('V', $offbits);

        $image = @imagecreatefromstring($header . $dib);
        if ($image === false) {
            return null;
        }
        ob_start();
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        return ($png === false || $png === '') ? null : $png;
    }

    /**
     * Interprets an unsigned 32-bit value as a signed integer.
     *
     * @param int $value The unsigned value from unpack('V').
     * @return int The signed equivalent.
     */
    private static function to_signed(int $value): int {
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    /**
     * Returns the external converter binaries that can be run, detected once.
     *
     * @return string[] The runnable converter binary names, in preference order.
     */
    private static function tools(): array {
        if (self::$tools !== null) {
            return self::$tools;
        }
        self::$tools = [];
        $candidates = [
            'convert' => ['-version', 'ImageMagick'],
            'magick' => ['-version', 'ImageMagick'],
            'soffice' => ['--version', 'LibreOffice'],
            'inkscape' => ['--version', 'Inkscape'],
        ];
        foreach ($candidates as $binary => $probe) {
            [$flag, $needle] = $probe;
            $result = self::run([$binary, $flag]);
            if ($result['started'] && stripos($result['out'] . $result['err'], $needle) !== false) {
                self::$tools[] = $binary;
            }
        }
        // ImageMagick exposes both "convert" and "magick"; one is enough.
        if (in_array('convert', self::$tools, true) && in_array('magick', self::$tools, true)) {
            self::$tools = array_values(array_diff(self::$tools, ['magick']));
        }
        return self::$tools;
    }

    /**
     * Runs a command with arguments passed as an array (no shell, so no injection).
     *
     * @param string[] $command The command and its arguments.
     * @return array{started:bool,out:string,err:string} The run result.
     */
    private static function run(array $command): array {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['started' => false, 'out' => '', 'err' => ''];
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return ['started' => true, 'out' => (string) $out, 'err' => (string) $err];
    }
}
