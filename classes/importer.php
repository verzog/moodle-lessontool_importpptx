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
 * Orchestrates a PowerPoint import into a lesson.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx;

use local_lessonimportpptx\pptx\package;
use local_lessonimportpptx\pptx\slide;
use local_lessonimportpptx\pptx\html_builder;

/**
 * Reads a .pptx and creates one lesson content page per slide, in slide order.
 *
 * Lesson has no page hierarchy, so a section-divider slide becomes a styled
 * content page like any other; the pages are chained linearly with a Continue
 * button each, appended after any pages the lesson already has.
 */
class importer {
    /** @var \stdClass The target lesson record. */
    private \stdClass $lesson;

    /** @var \context_module The lesson's module context. */
    private \context_module $context;

    /** @var string Section-plate colour chosen for this import. */
    private string $sectioncolour;

    /** @var int Maximum image dimension in px for this import (0 keeps originals). */
    private int $imagemaxdim;

    /**
     * Constructor.
     *
     * @param \stdClass $lesson The lesson activity record.
     * @param \context_module $context The lesson's module context.
     * @param array $options Import options: 'sectioncolour' (string) and 'imagemaxdim' (int).
     */
    public function __construct(\stdClass $lesson, \context_module $context, array $options = []) {
        $this->lesson = $lesson;
        $this->context = $context;
        $colour = (string) ($options['sectioncolour'] ?? '#442980');
        $this->sectioncolour = $colour === '' ? '#442980' : $colour;
        $this->imagemaxdim = (int) ($options['imagemaxdim'] ?? 1600);
    }

    /**
     * Counts the slides in a presentation without importing it.
     *
     * @param \stored_file $pptx The uploaded presentation.
     * @return int The number of slides.
     */
    public static function count_slides(\stored_file $pptx): int {
        $path = self::stage($pptx);
        $package = new package($path);
        try {
            return count($package->get_slide_paths());
        } finally {
            $package->close();
        }
    }

    /**
     * Imports the presentation, creating content pages and saving images.
     *
     * @param \stored_file $pptx The uploaded presentation.
     * @return int The number of pages created.
     */
    public function import(\stored_file $pptx): int {
        global $DB;

        $maxdim = $this->imagemaxdim;

        // The per-lesson lock serialises concurrent imports so page-chain appends
        // cannot race; the transaction makes the whole import atomic, so a failure
        // part-way through (or an adhoc-task retry after one) never leaves or
        // duplicates partial pages.
        $lock = page_writer::acquire_lock($this->lesson->id);
        try {
            $path = self::stage($pptx);
            $package = new package($path);
            $builder = new html_builder($this->sectioncolour);

            try {
                $slidepaths = $package->get_slide_paths();
                $created = 0;

                $transaction = $DB->start_delegated_transaction();
                try {
                    foreach ($slidepaths as $index => $slidepath) {
                        $parsed = (new slide($package, $slidepath))->parse();
                        $page = $builder->build($parsed);

                        $title = $page->title;
                        if ($title === null || trim($title) === '') {
                            $title = get_string('slidetitle', 'local_lessonimportpptx', $index + 1);
                        }

                        $this->write_page($package, $title, $page->html, $page->images, $maxdim);
                        $created++;
                    }

                    $DB->set_field('lesson', 'timemodified', time(), ['id' => $this->lesson->id]);
                    $transaction->allow_commit();
                } catch (\Throwable $e) {
                    $transaction->rollback($e);
                }

                return $created;
            } finally {
                $package->close();
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Writes one content page and saves its images into mod_lesson's file area.
     *
     * @param package $package The open package (source of image bytes).
     * @param string $title The page title (plain text).
     * @param string $html The page body HTML (with @@PLUGINFILE@@ references).
     * @param array $images Map of page filename to source media path in the package.
     * @param int $maxdim Maximum image dimension in px (0 keeps originals).
     * @return void
     */
    private function write_page(
        package $package,
        string $title,
        string $html,
        array $images,
        int $maxdim
    ): void {
        // Prepare each image before the page is written, staging the result on
        // disk so only one image is held in memory at a time. Vector formats a
        // browser cannot display (WMF/EMF) are converted to PNG when possible;
        // images that cannot be prepared are removed from the HTML so the page
        // never references a broken or unrenderable file.
        $stagedir = make_request_directory();
        $ready = [];
        $failed = [];
        $index = 0;
        foreach ($images as $filename => $mediapath) {
            $bytes = $package->get_bytes($mediapath);
            if ($bytes === null || $bytes === '') {
                $failed[] = $filename;
                continue;
            }
            $ext = strtolower((string) pathinfo($mediapath, PATHINFO_EXTENSION));
            if ($ext === 'wmf' || $ext === 'emf') {
                $bytes = \local_lessonimportpptx\graphics\converter::to_png($bytes, $ext);
                if ($bytes === null) {
                    $failed[] = $filename;
                    continue;
                }
            }
            if ($maxdim > 0) {
                $bytes = self::downscale($bytes, $maxdim);
            }
            $staged = $stagedir . '/' . $index++;
            if (file_put_contents($staged, $bytes) === false) {
                $failed[] = $filename;
                continue;
            }
            unset($bytes);
            $ready[$filename] = $staged;
        }
        if (!empty($failed)) {
            $html = self::strip_images($html, $failed);
        }

        page_writer::write($this->lesson, $this->context, $title, $html, $ready);
    }

    /**
     * Removes the images that could not be prepared, along with any figure,
     * column or grid container they leave empty, so the page reflows cleanly.
     *
     * @param string $html The page HTML.
     * @param string[] $filenames Filenames (case-sensitive) whose image failed.
     * @return string The cleaned HTML.
     */
    private static function strip_images(string $html, array $filenames): string {
        if (trim($html) === '' || empty($filenames)) {
            return $html;
        }
        $failed = array_fill_keys($filenames, true);

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="utf-8"?><body>' . $html . '</body>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return $html;
        }

        $xpath = new \DOMXPath($doc);
        foreach (iterator_to_array($xpath->query('//img')) as $img) {
            $src = $img->getAttribute('src');
            if (preg_match('#@@PLUGINFILE@@/(.+)$#', $src, $m) && isset($failed[$m[1]])) {
                $img->parentNode->removeChild($img);
            }
        }

        // Remove figures and image cells that no longer hold an image, then any
        // grid/column rows left without cells, repeating until nothing else clears.
        $has = static function (string $class): string {
            return 'contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")';
        };
        $cells = '//*[' . $has('local-lessonimportpptx-figure') . '][not(.//img)]'
            . ' | //*[' . $has('local-lessonimportpptx-grid') . ']/*[not(.//img)]';
        $rows = '//*[' . $has('local-lessonimportpptx-grid') . '][not(*)]'
            . ' | //*[' . $has('local-lessonimportpptx-cols') . '][not(*)]'
            . ' | //*[contains(concat(" ", normalize-space(@class), " "), " col-")][not(*) and not(normalize-space(.))]';
        do {
            $removed = false;
            foreach (iterator_to_array($xpath->query($cells . ' | ' . $rows)) as $node) {
                if ($node->parentNode !== null) {
                    $node->parentNode->removeChild($node);
                    $removed = true;
                }
            }
        } while ($removed);

        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return $html;
        }
        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    /**
     * Copies a stored file to a local temporary path ZipArchive can open.
     *
     * @param \stored_file $file The stored file.
     * @return string Absolute path to the staged copy.
     */
    private static function stage(\stored_file $file): string {
        $dir = make_request_directory();
        $path = $dir . '/import.pptx';
        $file->copy_content_to($path);
        return $path;
    }

    /**
     * Down-scales image bytes so the longest edge is at most $maxdim, using GD.
     *
     * Falls back to the original bytes when GD is unavailable, the format is
     * unsupported, or the image is already within bounds. GD is a bundled PHP
     * extension, not an external binary, so this respects the no-shell-out rule.
     *
     * @param string $bytes The original image bytes.
     * @param int $maxdim Maximum longest-edge size in pixels.
     * @return string The resized bytes, or the originals if no change was made.
     */
    private static function downscale(string $bytes, int $maxdim): string {
        if (!function_exists('imagecreatefromstring')) {
            return $bytes;
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return $bytes;
        }
        [$width, $height] = $info;
        if ($width <= $maxdim && $height <= $maxdim) {
            return $bytes;
        }
        $type = $info[2];
        $supported = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
        if (!in_array($type, $supported, true)) {
            return $bytes;
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return $bytes;
        }
        $scale = $maxdim / max($width, $height);
        $newwidth = max(1, (int) round($width * $scale));
        $newheight = max(1, (int) round($height * $scale));
        $resized = imagescale($source, $newwidth, $newheight);
        imagedestroy($source);
        if ($resized === false) {
            return $bytes;
        }

        ob_start();
        switch ($type) {
            case IMAGETYPE_PNG:
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                imagepng($resized);
                break;
            case IMAGETYPE_GIF:
                imagegif($resized);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($resized);
                break;
            default:
                imagejpeg($resized, null, 85);
                break;
        }
        $out = ob_get_clean();
        imagedestroy($resized);
        return ($out === false || $out === '') ? $bytes : $out;
    }
}
