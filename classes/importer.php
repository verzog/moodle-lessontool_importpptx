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
use local_lessonimportpptx\office\renderer;

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

    /** @var bool Whether plain image runs are rendered as Bootstrap card groups. */
    private bool $cardgroup;

    /** @var int Point size forced on body text (0 keeps the slide's own sizes). */
    private int $bodysize;

    /** @var int Point size forced on text beside an image (0 keeps the slide's own sizes). */
    private int $adjacentsize;

    /** @var bool Whether SmartArt slides are kept as rendered images rather than flattened. */
    private bool $smartartimages;

    /** @var renderer|null The slide-image render backend (injectable for testing). */
    private ?renderer $renderer;

    /**
     * Constructor.
     *
     * @param \stdClass $lesson The lesson activity record.
     * @param \context_module $context The lesson's module context.
     * @param array $options Import options: 'sectioncolour' (string), 'imagemaxdim'
     *                       (int), 'cardgroup' (bool), 'bodysize' (int pt),
     *                       'adjacentsize' (int pt) and 'smartartimages' (bool).
     * @param renderer|null $renderer The image render backend, or null for the default.
     */
    public function __construct(
        \stdClass $lesson,
        \context_module $context,
        array $options = [],
        ?renderer $renderer = null
    ) {
        $this->lesson = $lesson;
        $this->context = $context;
        $colour = (string) ($options['sectioncolour'] ?? '#442980');
        $this->sectioncolour = $colour === '' ? '#442980' : $colour;
        $this->imagemaxdim = (int) ($options['imagemaxdim'] ?? 1600);
        $this->cardgroup = !empty($options['cardgroup']);
        $this->bodysize = max(0, (int) ($options['bodysize'] ?? 0));
        $this->adjacentsize = max(0, (int) ($options['adjacentsize'] ?? 0));
        $this->smartartimages = !empty($options['smartartimages']);
        $this->renderer = $renderer;
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
            $builder = new html_builder($this->sectioncolour, $this->cardgroup, $this->bodysize, $this->adjacentsize);

            try {
                $slidepaths = $package->get_slide_paths();
                $created = 0;

                // Some slides do not survive the trip to editable HTML (SmartArt,
                // and a dominant picture overlaid with caption labels); when asked
                // (and able), keep those slides as rendered images. Render and
                // stage BEFORE the transaction: the LibreOffice pass can run for a
                // while, and a delegated transaction must not be held open across it.
                $stagedir = make_request_directory();
                $slideimages = $this->complex_slide_images($pptx, $package, $slidepaths, $maxdim, $stagedir);

                $transaction = $DB->start_delegated_transaction();
                try {
                    foreach ($slidepaths as $index => $slidepath) {
                        $parsed = (new slide($package, $slidepath))->parse();
                        $page = $builder->build($parsed);

                        $title = $page->title;
                        if ($title === null || trim($title) === '') {
                            $title = get_string('slidetitle', 'local_lessonimportpptx', $index + 1);
                        }

                        if (isset($slideimages[$index])) {
                            // Keep this SmartArt slide as its rendered image.
                            [$filename, $filepath] = $slideimages[$index];
                            $html = '<img src="@@PLUGINFILE@@/' . $filename . '" alt="' . s($title)
                                . '" class="img-fluid">';
                            page_writer::write($this->lesson, $this->context, $title, $html, [$filename => $filepath]);
                        } else {
                            $this->write_page($package, $title, $page->html, $page->images, $maxdim);
                        }
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
     * Renders each "complex" slide — one that does not survive the trip to
     * editable HTML — to a faithful image staged on disk, keyed by slide index.
     *
     * Two kinds of slide are kept as images when the option is on and the
     * LibreOffice render backend is available: a slide carrying a SmartArt
     * diagram (which otherwise flattens to a bare bullet list), and a slide that
     * is a single dominant picture overlaid with caption labels (which otherwise
     * lose those labels to orphaned lines below the image). The backend renders
     * each visible slide to a numbered page (hidden slides are skipped), so slide
     * indices are mapped to page numbers by counting visible slides. Each wanted
     * page's bytes are written to a staged file for page_writer to save.
     *
     * @param \stored_file $pptx The uploaded presentation.
     * @param package $package The open package (source of slide XML).
     * @param string[] $slidepaths The slide part paths, in order.
     * @param int $maxdim Maximum image dimension in px (0 keeps the rendered size).
     * @param string $stagedir A writable directory for staged image files.
     * @return array Map of slide index to [filename, stagedpath] for kept slides.
     */
    private function complex_slide_images(
        \stored_file $pptx,
        package $package,
        array $slidepaths,
        int $maxdim,
        string $stagedir
    ): array {
        // Check the option before probing the renderer: an ordinary editable
        // import must not pay the LibreOffice availability probe.
        if (!$this->smartartimages) {
            return [];
        }
        $renderer = $this->renderer ?? (renderer::is_available() ? new renderer() : null);
        if ($renderer === null) {
            return [];
        }
        $slidearea = $package->slide_width() * $package->slide_height();
        // Which visible slides are complex, keyed by their 1-based render page.
        // A hidden complex slide (show="0") cannot be imaged — the renderer omits
        // it from the render — so it keeps its editable (flattened) content.
        $wanted = [];
        $visible = 0;
        foreach ($slidepaths as $index => $slidepath) {
            $doc = $package->get_xml($slidepath);
            if (self::slide_is_hidden($doc)) {
                continue;
            }
            $visible++;
            if (self::slide_has_smartart($doc) || self::slide_is_image_dominant($doc, $slidearea)) {
                $wanted[$visible] = $index;
            }
        }
        // The render backend caps at MAX_PAGES; the editable parser allows more
        // slides. If the whole-deck render would be refused, skip imaging so the
        // import still succeeds (SmartArt slides fall back to flattened content).
        if (empty($wanted) || $visible > pdf\renderer::MAX_PAGES) {
            return [];
        }
        $images = [];
        foreach ($renderer->render_pages($pptx, $maxdim) as [$rendered, $filename, $bytes]) {
            if (!isset($wanted[$rendered])) {
                continue;
            }
            $file = $stagedir . '/smartart-' . $rendered;
            if (file_put_contents($file, $bytes) === false) {
                throw new \moodle_exception('errorofficerender', 'local_lessonimportpptx');
            }
            $images[$wanted[$rendered]] = [$filename, $file];
        }
        return $images;
    }

    /**
     * Whether a slide is hidden (p:sld show="0"), which the renderer skips.
     *
     * @param \DOMDocument|null $doc The parsed slide document, or null.
     * @return bool True if the slide is marked hidden.
     */
    private static function slide_is_hidden(?\DOMDocument $doc): bool {
        if ($doc === null) {
            return false;
        }
        $root = $doc->documentElement;
        return $root instanceof \DOMElement && $root->getAttribute('show') === '0';
    }

    /**
     * Whether a slide carries a SmartArt diagram.
     *
     * SmartArt is a graphicFrame holding a diagram data-model relationship (r:dm);
     * charts (r:id) and tables (a:tbl) do not, so they are not matched.
     *
     * @param \DOMDocument|null $doc The parsed slide document, or null.
     * @return bool True if the slide contains a SmartArt diagram.
     */
    private static function slide_has_smartart(?\DOMDocument $doc): bool {
        if ($doc === null) {
            return false;
        }
        $xpath = new \DOMXPath($doc);
        return $xpath->query("//*[local-name()='graphicFrame']//*[@*[local-name()='dm']]")->length > 0;
    }

    /**
     * Whether a slide is a single dominant picture overlaid with caption labels.
     *
     * Such a slide (for example a composite figure with "before / after" photos
     * and small labels placed over regions of the picture) reads far better kept
     * as its rendered image: flattening it drops the geometrically-positioned
     * labels into orphaned lines below the picture. The test is deliberately
     * tight to leave ordinary photo-and-caption slides editable: the largest
     * picture must cover at least 40% of the slide, and at least two short,
     * caption-length text boxes must overlap that picture's box.
     *
     * @param \DOMDocument|null $doc The parsed slide document, or null.
     * @param int $slidearea The slide area in EMU² (0 when unknown).
     * @return bool True if the slide is a picture overlaid with caption labels.
     */
    private static function slide_is_image_dominant(?\DOMDocument $doc, int $slidearea): bool {
        if ($doc === null || $slidearea <= 0) {
            return false;
        }
        $xpath = new \DOMXPath($doc);
        // Pictures: a <p:pic>, or a shape filled with a picture (blipFill).
        $pics = $xpath->query("//*[local-name()='pic'] | //*[local-name()='sp'][.//*[local-name()='blipFill']]");
        $biggest = null;
        $biggestarea = 0;
        foreach ($pics as $pic) {
            $box = self::shape_box($xpath, $pic);
            if ($box !== null && $box[2] * $box[3] > $biggestarea) {
                $biggestarea = $box[2] * $box[3];
                $biggest = $box;
            }
        }
        if ($biggest === null || $biggestarea * 100 < $slidearea * 40) {
            return false;
        }
        // Count short (caption-length) text boxes overlapping the dominant picture.
        $overlaid = 0;
        foreach ($xpath->query("//*[local-name()='sp'][not(.//*[local-name()='blipFill'])]") as $sp) {
            $text = trim($sp->textContent);
            if ($text === '' || \core_text::strlen($text) > 60) {
                continue;
            }
            $box = self::shape_box($xpath, $sp);
            if ($box !== null && self::boxes_overlap($box, $biggest)) {
                $overlaid++;
            }
        }
        return $overlaid >= 2;
    }

    /**
     * Reads a shape's bounding box (x, y, cx, cy in EMU) from its first xfrm.
     *
     * @param \DOMXPath $xpath An xpath bound to the shape's document.
     * @param \DOMNode $shape The shape element.
     * @return int[]|null [x, y, cx, cy], or null when no positive-sized box is set.
     */
    private static function shape_box(\DOMXPath $xpath, \DOMNode $shape): ?array {
        $xfrm = $xpath->query(".//*[local-name()='xfrm'][1]", $shape)->item(0);
        if ($xfrm === null) {
            return null;
        }
        $off = $xpath->query("./*[local-name()='off']", $xfrm)->item(0);
        $ext = $xpath->query("./*[local-name()='ext']", $xfrm)->item(0);
        if ($off === null || $ext === null) {
            return null;
        }
        $cx = (int) $ext->getAttribute('cx');
        $cy = (int) $ext->getAttribute('cy');
        if ($cx <= 0 || $cy <= 0) {
            return null;
        }
        return [(int) $off->getAttribute('x'), (int) $off->getAttribute('y'), $cx, $cy];
    }

    /**
     * Whether two [x, y, cx, cy] boxes share a positive overlap area.
     *
     * @param int[] $a A box as [x, y, cx, cy].
     * @param int[] $b A box as [x, y, cx, cy].
     * @return bool True when the boxes overlap.
     */
    private static function boxes_overlap(array $a, array $b): bool {
        $ox = min($a[0] + $a[2], $b[0] + $b[2]) - max($a[0], $b[0]);
        $oy = min($a[1] + $a[3], $b[1] + $b[3]) - max($a[1], $b[1]);
        return $ox > 0 && $oy > 0;
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
            // Audio is not an image: stage its bytes as-is, skipping the GD
            // conversion, blank-detection and downscaling that only apply to images.
            if (self::is_audio_ext($ext)) {
                $staged = $stagedir . '/' . $index++;
                if (file_put_contents($staged, $bytes) === false) {
                    $failed[] = $filename;
                    continue;
                }
                unset($bytes);
                $ready[$filename] = $staged;
                continue;
            }
            if ($ext === 'wmf' || $ext === 'emf') {
                $bytes = \local_lessonimportpptx\graphics\converter::to_png($bytes, $ext);
                if ($bytes === null) {
                    $failed[] = $filename;
                    continue;
                }
            }
            // A blank placeholder rectangle (a white or transparent picture frame)
            // imports as an empty card, so drop it like a failed image and let the
            // cleanup pass take its card and zoom modal with it.
            if (self::is_blank($bytes)) {
                $failed[] = $filename;
                continue;
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
        // A failed audio clip drops its whole player, not just the <source>.
        foreach (iterator_to_array($xpath->query('//source')) as $source) {
            $src = $source->getAttribute('src');
            if (preg_match('#@@PLUGINFILE@@/(.+)$#', $src, $m) && isset($failed[$m[1]])) {
                $audio = $source->parentNode;
                if ($audio !== null && $audio->parentNode !== null) {
                    $audio->parentNode->removeChild($audio);
                }
            }
        }

        // Remove figures and image cells that no longer hold an image, then any
        // grid/column rows left without cells, repeating until nothing else clears.
        $has = static function (string $class): string {
            return 'contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")';
        };
        // A card and its zoom modal both reference the same image, so a failed
        // image empties both: drop the card cell and the now-imageless modal, then
        // any card-group row that is left with no cards.
        $cells = '//*[' . $has('local-lessonimportpptx-figure') . '][not(.//img)]'
            . ' | //*[' . $has('local-lessonimportpptx-grid') . ']/*[not(.//img)]'
            . ' | //*[' . $has('local-lessonimportpptx-card') . '][not(.//img)]'
            . ' | //*[' . $has('local-lessonimportpptx-cardmodal') . '][not(.//img)]';
        $rows = '//*[' . $has('local-lessonimportpptx-grid') . '][not(*)]'
            . ' | //*[' . $has('local-lessonimportpptx-cols') . '][not(*)]'
            . ' | //*[' . $has('local-lessonimportpptx-cardgroup') . '][not(*)]'
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

        // A card group thinned to a single uncaptioned image is no longer a group:
        // rebuild it as the centred, height-capped figure a lone image uses (and
        // drop the now-triggerless zoom modal) so it does not keep the half-width,
        // uncapped one-card layout.
        foreach (iterator_to_array($xpath->query('//*[' . $has('local-lessonimportpptx-cardgroup') . ']')) as $group) {
            if ($xpath->query('.//*[' . $has('local-lessonimportpptx-card') . ']', $group)->length !== 1) {
                continue;
            }
            $img = $xpath->query('.//img', $group)->item(0);
            $caption = $xpath->query('.//*[' . $has('card-body') . ']', $group)->item(0);
            if (!$img instanceof \DOMElement || $caption !== null) {
                continue;
            }
            $figure = $doc->createElement('div');
            $figure->setAttribute('class', 'local-lessonimportpptx-figure');
            $figimg = $doc->createElement('img');
            $figimg->setAttribute('src', $img->getAttribute('src'));
            $figimg->setAttribute('alt', '');
            $figimg->setAttribute('class', 'img-fluid');
            $figure->appendChild($figimg);
            $trigger = $xpath->query('.//a[@data-bs-target]', $group)->item(0);
            $group->parentNode->replaceChild($figure, $group);
            if ($trigger instanceof \DOMElement) {
                $target = ltrim($trigger->getAttribute('data-bs-target'), '#');
                foreach ($target === '' ? [] : iterator_to_array($xpath->query('//*[@id="' . $target . '"]')) as $modal) {
                    if ($modal->parentNode !== null) {
                        $modal->parentNode->removeChild($modal);
                    }
                }
            }
        }

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

    /** @var int Images larger than this many pixels are kept without a blank scan. */
    private const BLANK_SCAN_MAX_PIXELS = 2000000;

    /**
     * Whether a media extension is an audio format the importer copies verbatim.
     *
     * @param string $ext The lower-case file extension (no dot).
     * @return bool True for a recognised audio extension.
     */
    private static function is_audio_ext(string $ext): bool {
        return in_array($ext, ['m4a', 'mp4', 'aac', 'mp3', 'oga', 'ogg', 'wav'], true);
    }

    /**
     * Detects an effectively blank image: one whose pixels are all either
     * transparent or near-white.
     *
     * PowerPoint decks routinely carry white placeholder rectangles and empty
     * picture frames that import as blank cards. These are treated like a failed
     * image and pruned with their card and modal. A solid non-white graphic (a
     * colour swatch, a logo, a photo) is not blank and is kept. GD is a bundled
     * PHP extension, so this respects the no-shell-out rule; when GD or the format
     * is unavailable the image is kept rather than guessed at.
     *
     * The verdict is destructive (a blank image is removed), so every pixel is
     * inspected rather than sampled: a sample grid could miss a sparse line and
     * delete a valid graphic. Dimensions are read first without decoding, and any
     * image above a pixel cap is kept unscanned, so a huge compressed source
     * cannot be expanded into memory just to test it.
     *
     * @param string $bytes The prepared image bytes.
     * @return bool True when every pixel is transparent or near-white.
     */
    private static function is_blank(string $bytes): bool {
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return false;
        }
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width * $height > self::BLANK_SCAN_MAX_PIXELS) {
            return false;
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return false;
        }
        // Normalise palette images so channel extraction below is uniform.
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        $blank = true;
        for ($y = 0; $y < $height && $blank; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($image, $x, $y);
                // GD packs truecolor as (alpha << 24) | (r << 16) | (g << 8) | b,
                // with alpha 0 (opaque) to 127 (fully transparent).
                if ((($rgba >> 24) & 0x7F) >= 120) {
                    continue;
                }
                if ((($rgba >> 16) & 0xFF) >= 250 && (($rgba >> 8) & 0xFF) >= 250 && ($rgba & 0xFF) >= 250) {
                    continue;
                }
                $blank = false;
                break;
            }
        }
        imagedestroy($image);
        return $blank;
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
