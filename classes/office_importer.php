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
 * Imports a presentation into a lesson as one rendered image per slide.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx;

use local_lessonimportpptx\office\renderer;
use local_lessonimportpptx\pptx\package;
use local_lessonimportpptx\pptx\slide;
use local_lessonimportpptx\pptx\html_builder;

/**
 * "Whole deck as images" backend: renders every slide to a faithful image with
 * LibreOffice (via {@see renderer}) and creates one image content page per slide,
 * in order. Use this when a deck's slides must look exactly as in PowerPoint and
 * editable text is not required.
 */
class office_importer {
    /** @var \stdClass The target lesson record. */
    private \stdClass $lesson;

    /** @var \context_module The lesson's module context. */
    private \context_module $context;

    /** @var int Maximum image dimension in px (0 keeps the rendered size). */
    private int $imagemaxdim;

    /** @var renderer|null The render backend (injectable for testing). */
    private ?renderer $renderer;

    /**
     * Constructor.
     *
     * @param \stdClass $lesson The lesson activity record.
     * @param \context_module $context The lesson's module context.
     * @param array $options Import options ('imagemaxdim' int).
     * @param renderer|null $renderer The render backend, or null to build the default.
     */
    public function __construct(\stdClass $lesson, \context_module $context, array $options = [], ?renderer $renderer = null) {
        $this->lesson = $lesson;
        $this->context = $context;
        $this->imagemaxdim = (int) ($options['imagemaxdim'] ?? 1600);
        $this->renderer = $renderer;
    }

    /**
     * Counts the slides in a presentation for the image backend.
     *
     * The whole-deck image path does not use the transitional-OOXML parser, so a
     * deck that parser rejects (for example Strict Open XML) can still be
     * rendered by LibreOffice. Counting its slide parts straight from the archive
     * keeps the confirmation count and async threshold working for those decks,
     * instead of failing them at the counting step the editable path uses.
     *
     * @param \stored_file $pptx The uploaded presentation.
     * @return int The number of slide parts in the package.
     * @throws \moodle_exception If the file is not a readable .pptx package.
     */
    public static function count_slides(\stored_file $pptx): int {
        $dir = make_request_directory();
        $path = $dir . '/count.pptx';
        $pptx->copy_content_to($path);
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \moodle_exception('errornopptx', 'local_lessonimportpptx');
        }
        try {
            $count = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name !== false && preg_match('#^ppt/slides/slide\d+\.xml$#', $name)) {
                    $count++;
                }
            }
            return $count;
        } finally {
            $zip->close();
        }
    }

    /**
     * Returns the title for a rendered page: the slide's own title, else "Slide N".
     *
     * @param array $titles Map of 1-based slide position to title text.
     * @param int $page The 1-based rendered page (slide) number.
     * @return string The page title, bounded to a database-safe length.
     */
    private static function page_title(array $titles, int $page): string {
        $title = isset($titles[$page]) ? trim((string) $titles[$page]) : '';
        if ($title === '') {
            return get_string('slidetitle', 'local_lessonimportpptx', $page);
        }
        return \core_text::substr($title, 0, 255);
    }

    /**
     * Extracts each slide's title text, in slide order, for the image backend.
     *
     * The rendered pages carry no text, so titles are read straight from the
     * archive with namespace-agnostic XPath — which also copes with Strict Open
     * XML, exactly the kind of deck the image path exists for. Slides with no
     * title placeholder map to an empty string, and any read failure yields an
     * empty map so the caller falls back to numbered titles.
     *
     * @param \stored_file $pptx The uploaded presentation.
     * @return array Map of 1-based slide position to title text.
     */
    private static function extract_titles(\stored_file $pptx): array {
        try {
            $dir = make_request_directory();
            $path = $dir . '/titles.pptx';
            $pptx->copy_content_to($path);
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                return [];
            }
            try {
                $titles = [];
                $position = 0;
                foreach (self::slide_order($zip) as $slidepath) {
                    $doc = self::load_xml($zip, $slidepath);
                    // LibreOffice omits hidden slides from the PDF by default, so
                    // skip them here too, keeping the title positions aligned with
                    // the visible rendered pages.
                    if ($doc !== null && self::is_hidden($doc)) {
                        continue;
                    }
                    $position++;
                    $titles[$position] = $doc !== null ? self::slide_title_text($doc) : '';
                }
                return $titles;
            } finally {
                $zip->close();
            }
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Returns the slide part paths in presentation order.
     *
     * @param \ZipArchive $zip The open package.
     * @return string[] Zip entry names of the slides, in order.
     */
    private static function slide_order(\ZipArchive $zip): array {
        $presentation = self::load_xml($zip, 'ppt/presentation.xml');
        $rels = self::load_xml($zip, 'ppt/_rels/presentation.xml.rels');
        if ($presentation === null || $rels === null) {
            return [];
        }
        $targets = [];
        foreach ($rels->getElementsByTagName('*') as $rel) {
            if ($rel->localName === 'Relationship' && $rel->getAttribute('Id') !== '') {
                $targets[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
            }
        }
        $paths = [];
        $xpath = new \DOMXPath($presentation);
        foreach ($xpath->query("//*[local-name()='sldIdLst']/*[local-name()='sldId']") as $sldid) {
            $rid = self::relationship_id($sldid);
            $target = ($rid !== null && isset($targets[$rid])) ? self::resolve_target($targets[$rid]) : null;
            if ($target !== null && $zip->locateName($target) !== false) {
                $paths[] = $target;
            }
        }
        return $paths;
    }

    /**
     * Reads the relationship id (r:id) of a sldId element, namespace-agnostically.
     *
     * @param \DOMElement $sldid The p:sldId element.
     * @return string|null The relationship id, or null if absent.
     */
    private static function relationship_id(\DOMElement $sldid): ?string {
        foreach (iterator_to_array($sldid->attributes) as $attr) {
            if ($attr->localName === 'id' && (string) $attr->namespaceURI !== '') {
                return $attr->value;
            }
        }
        return null;
    }

    /**
     * Resolves a presentation relationship target to a package (zip) path.
     *
     * @param string $target The relationship Target (relative to ppt/, or absolute).
     * @return string|null The normalised zip entry name, or null if empty.
     */
    private static function resolve_target(string $target): ?string {
        if ($target === '') {
            return null;
        }
        if ($target[0] === '/') {
            return ltrim($target, '/');
        }
        $parts = [];
        foreach (explode('/', 'ppt/' . $target) as $seg) {
            if ($seg === '..') {
                array_pop($parts);
            } else if ($seg !== '.' && $seg !== '') {
                $parts[] = $seg;
            }
        }
        return implode('/', $parts);
    }

    /**
     * Whether a slide is hidden (p:sld show="0"), which LibreOffice skips on export.
     *
     * @param \DOMDocument $doc The parsed slide document.
     * @return bool True if the slide is marked hidden.
     */
    private static function is_hidden(\DOMDocument $doc): bool {
        $root = $doc->documentElement;
        return $root instanceof \DOMElement && $root->getAttribute('show') === '0';
    }

    /**
     * Extracts a slide's page title for the image backend.
     *
     * Prefers the title placeholder; failing that, promotes the topmost short,
     * single-line text box, mirroring the editable importer so a styled heading
     * (a plain text box rather than a title placeholder — e.g. "Workshop Session
     * 7") still names the page instead of falling back to "Slide N".
     *
     * @param \DOMDocument $doc The parsed slide document.
     * @return string The title text (empty when none can be derived).
     */
    private static function slide_title_text(\DOMDocument $doc): string {
        $xpath = new \DOMXPath($doc);
        $query = "//*[local-name()='sp'][.//*[local-name()='ph'][@type='title' or @type='ctrTitle']][1]";
        $sp = $xpath->query($query)->item(0);
        if ($sp instanceof \DOMElement) {
            $text = self::shape_line($xpath, $sp);
            if ($text !== '') {
                return $text;
            }
        }
        return self::promote_leading_line($xpath);
    }

    /**
     * Joins a shape's non-empty paragraph text into one whitespace-collapsed line.
     *
     * @param \DOMXPath $xpath An xpath bound to the shape's document.
     * @param \DOMElement $sp The shape element.
     * @return string The shape's text, collapsed to a single line.
     */
    private static function shape_line(\DOMXPath $xpath, \DOMElement $sp): string {
        $lines = [];
        foreach ($xpath->query(".//*[local-name()='p']", $sp) as $paragraph) {
            $line = '';
            foreach ($xpath->query(".//*[local-name()='t']", $paragraph) as $run) {
                $line .= $run->textContent;
            }
            if (trim($line) !== '') {
                $lines[] = $line;
            }
        }
        return trim(preg_replace('/\s+/u', ' ', implode(' ', $lines)));
    }

    /**
     * Promotes the topmost short, single-line text box to a title.
     *
     * Mirrors the editable importer: the slide's leading content, when it is a
     * single short line (not a multi-line body, and not a one-or-two-character
     * badge), becomes the page title. Footer/slide-number/date furniture and
     * title placeholders are skipped, and the box highest on the slide wins.
     *
     * @param \DOMXPath $xpath An xpath bound to the slide document.
     * @return string The promoted title, or '' when nothing qualifies.
     */
    private static function promote_leading_line(\DOMXPath $xpath): string {
        $best = '';
        $besttop = null;
        $skip = ['ftr', 'sldNum', 'dt', 'title', 'ctrTitle'];
        foreach ($xpath->query("//*[local-name()='sp']") as $sp) {
            $ph = $xpath->query(".//*[local-name()='ph']", $sp)->item(0);
            if ($ph instanceof \DOMElement && in_array($ph->getAttribute('type'), $skip, true)) {
                continue;
            }
            $lines = [];
            foreach ($xpath->query(".//*[local-name()='txBody']/*[local-name()='p']", $sp) as $paragraph) {
                $line = '';
                foreach ($xpath->query(".//*[local-name()='t']", $paragraph) as $run) {
                    $line .= $run->textContent;
                }
                if (trim($line) !== '') {
                    $lines[] = trim(preg_replace('/\s+/u', ' ', $line));
                }
            }
            if (count($lines) !== 1) {
                continue;
            }
            $length = \core_text::strlen($lines[0]);
            if ($length <= slide::BADGE_MAX_CHARS || $length > html_builder::TITLE_FALLBACK_MAX_CHARS) {
                continue;
            }
            $top = self::shape_top($xpath, $sp);
            if ($besttop === null || $top < $besttop) {
                $besttop = $top;
                $best = $lines[0];
            }
        }
        return $best;
    }

    /**
     * Returns a shape's top edge (EMU) from its transform, or PHP_INT_MAX when the
     * shape carries no explicit position (so positioned shapes are preferred).
     *
     * @param \DOMXPath $xpath An xpath bound to the shape's document.
     * @param \DOMElement $sp The shape element.
     * @return int The top offset in EMU, or PHP_INT_MAX when unknown.
     */
    private static function shape_top(\DOMXPath $xpath, \DOMElement $sp): int {
        $off = $xpath->query(".//*[local-name()='xfrm']/*[local-name()='off']", $sp)->item(0);
        if ($off instanceof \DOMElement && $off->getAttribute('y') !== '') {
            return (int) $off->getAttribute('y');
        }
        return PHP_INT_MAX;
    }

    /**
     * Loads a package part into a DOMDocument, or null if missing/unparseable.
     *
     * @param \ZipArchive $zip The open package.
     * @param string $name The zip entry name.
     * @return \DOMDocument|null The parsed document, or null.
     */
    private static function load_xml(\ZipArchive $zip, string $name): ?\DOMDocument {
        // Bound the part by its declared uncompressed size before inflating it,
        // so a zip-bombed title/presentation part cannot exhaust the worker here
        // (this read happens before the render path's whole-archive size guard).
        $stat = $zip->statName($name);
        if ($stat === false || (int) $stat['size'] > package::MAX_PART_SIZE) {
            return null;
        }
        $data = $zip->getFromName($name);
        if ($data === false || $data === '') {
            return null;
        }
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($data, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $ok ? $doc : null;
    }

    /**
     * Imports the presentation, creating one image content page per slide.
     *
     * @param \stored_file $pptx The uploaded presentation.
     * @return int The number of pages created.
     */
    public function import(\stored_file $pptx): int {
        global $DB;

        // Same discipline as the other backends: the lock serialises concurrent
        // appends to the page chain, and the transaction makes the import atomic
        // so a failed run (or adhoc retry) never leaves or duplicates partial pages.
        $lock = page_writer::acquire_lock($this->lesson->id);
        try {
            $renderer = $this->renderer ?? new renderer();
            $stagedir = make_request_directory();
            $titles = self::extract_titles($pptx);

            // Render the whole deck to staged files BEFORE opening the transaction:
            // the LibreOffice conversion and rasterisation can run up to the
            // conversion timeout, and holding a delegated transaction open that
            // long trips idle-transaction limits on some databases. A staging
            // failure here aborts the whole import (so an adhoc retry can re-run
            // it cleanly) rather than silently committing a deck with a slide missing.
            $staged = [];
            foreach ($renderer->render_pages($pptx, $this->imagemaxdim) as [$page, $filename, $bytes]) {
                $file = $stagedir . '/' . $page;
                if (file_put_contents($file, $bytes) === false) {
                    throw new \moodle_exception('errorofficerender', 'local_lessonimportpptx');
                }
                $staged[] = [
                    'title' => self::page_title($titles, $page),
                    'filename' => $filename,
                    'path' => $file,
                ];
            }

            $created = 0;
            $transaction = $DB->start_delegated_transaction();
            try {
                foreach ($staged as $item) {
                    $html = '<img src="@@PLUGINFILE@@/' . $item['filename']
                        . '" alt="' . s($item['title']) . '" class="img-fluid">';
                    page_writer::write(
                        $this->lesson,
                        $this->context,
                        $item['title'],
                        $html,
                        [$item['filename'] => $item['path']]
                    );
                    $created++;
                }

                if ($created > 0) {
                    $DB->set_field('lesson', 'timemodified', time(), ['id' => $this->lesson->id]);
                }
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
            return $created;
        } finally {
            $lock->release();
        }
    }
}
