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
 * Reads the parts of a .pptx package needed for import.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx\pptx;

/**
 * Opens a PowerPoint package (a ZIP of XML) and exposes ordered slides, their
 * relationships, and media, using only PHP's bundled ZipArchive and DOMDocument.
 */
class package {
    /** @var int Default reference slide width in EMU (16:9). */
    const SLIDE_W_EMU = 12192000;

    /** @var int Default reference slide height in EMU (16:9). */
    const SLIDE_H_EMU = 6858000;

    /** @var int Upper bound on slides to guard against abusive uploads. */
    const MAX_SLIDES = 1000;

    /** @var int Maximum uncompressed size, in bytes, of any single part (anti zip-bomb). */
    const MAX_PART_SIZE = 104857600;

    /** @var int Maximum total uncompressed bytes inflated over the package's lifetime. */
    const MAX_TOTAL_SIZE = 1073741824;

    /** @var string DrawingML main namespace. */
    const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    /** @var string PresentationML namespace. */
    const NS_P = 'http://schemas.openxmlformats.org/presentationml/2006/main';

    /** @var string Strict OOXML PresentationML namespace (unsupported variant). */
    const NS_P_STRICT = 'http://purl.oclc.org/ooxml/presentationml/main';

    /** @var string Relationships namespace. */
    const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @var string Package relationships namespace (used inside .rels parts). */
    const NS_PR = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /** @var \ZipArchive The open package. */
    private \ZipArchive $zip;

    /** @var int Slide width in EMU, read from presentation.xml. */
    private int $slidewidth = self::SLIDE_W_EMU;

    /** @var int Slide height in EMU, read from presentation.xml. */
    private int $slideheight = self::SLIDE_H_EMU;

    /** @var int Running total of uncompressed bytes inflated from the package. */
    private int $inflated = 0;

    /**
     * Opens the package and validates it is a real PowerPoint file.
     *
     * @param string $path Absolute path to the .pptx file on disk.
     * @throws \moodle_exception If the file is not a readable .pptx package.
     */
    public function __construct(string $path) {
        $this->zip = new \ZipArchive();
        if ($this->zip->open($path) !== true) {
            throw new \moodle_exception('errornopptx', 'local_lessonimportpptx');
        }
        // A valid presentation must carry these parts.
        if (
            $this->zip->locateName('[Content_Types].xml') === false
                || $this->zip->locateName('ppt/presentation.xml') === false
        ) {
            throw new \moodle_exception('errornopptx', 'local_lessonimportpptx');
        }
        $this->reject_strict_ooxml();
        $this->read_dimensions();
    }

    /**
     * Rejects Strict Open XML presentations, whose namespaces differ from the
     * transitional ones this parser understands; importing them silently would
     * produce empty pages, so fail with a clear message instead.
     *
     * @return void
     * @throws \moodle_exception If the presentation uses the strict OOXML namespace.
     */
    private function reject_strict_ooxml(): void {
        $doc = $this->get_xml('ppt/presentation.xml');
        if ($doc === null) {
            return;
        }
        $root = $doc->documentElement;
        if ($root instanceof \DOMElement && $root->namespaceURI === self::NS_P_STRICT) {
            throw new \moodle_exception('errorstrictooxml', 'local_lessonimportpptx');
        }
    }

    /**
     * Reads slide dimensions from ppt/presentation.xml, falling back to 16:9.
     *
     * @return void
     */
    private function read_dimensions(): void {
        $doc = $this->get_xml('ppt/presentation.xml');
        if ($doc === null) {
            return;
        }
        $xpath = self::xpath($doc);
        $node = $xpath->query('/p:presentation/p:sldSz')->item(0);
        if ($node instanceof \DOMElement) {
            $cx = (int) $node->getAttribute('cx');
            $cy = (int) $node->getAttribute('cy');
            if ($cx > 0 && $cy > 0) {
                $this->slidewidth = $cx;
                $this->slideheight = $cy;
            }
        }
    }

    /**
     * Returns the slide part paths in presentation (reading) order.
     *
     * The order given by presentation.xml's sldIdLst is authoritative; numeric
     * filename order usually matches but is not guaranteed.
     *
     * @return string[] Zip paths such as "ppt/slides/slide1.xml".
     * @throws \moodle_exception If no slides can be located.
     */
    public function get_slide_paths(): array {
        $rels = $this->get_rels('ppt/presentation.xml');
        $doc = $this->get_xml('ppt/presentation.xml');
        $paths = [];
        if ($doc !== null) {
            $xpath = self::xpath($doc);
            foreach ($xpath->query('/p:presentation/p:sldIdLst/p:sldId') as $sldid) {
                $rid = $sldid->getAttributeNS(self::NS_R, 'id');
                if ($rid !== '' && isset($rels[$rid])) {
                    $paths[] = $rels[$rid];
                }
            }
        }

        // Fallback: if the id list was unreadable, sort slide parts by number.
        if (empty($paths)) {
            $found = [];
            for ($i = 0; $i < $this->zip->numFiles; $i++) {
                $name = $this->zip->getNameIndex($i);
                if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                    $found[(int) $m[1]] = $name;
                }
            }
            ksort($found);
            $paths = array_values($found);
        }

        if (empty($paths)) {
            throw new \moodle_exception('errornoslides', 'local_lessonimportpptx');
        }
        if (count($paths) > self::MAX_SLIDES) {
            throw new \moodle_exception('errortoomanyslides', 'local_lessonimportpptx', '', (object) [
                'count' => count($paths),
                'max' => self::MAX_SLIDES,
            ]);
        }
        return $paths;
    }

    /**
     * Loads a package part as an XXE-safe DOMDocument.
     *
     * @param string $path Zip path of the part.
     * @return \DOMDocument|null The parsed document, or null if missing/invalid.
     */
    public function get_xml(string $path): ?\DOMDocument {
        $xml = $this->read_entry($path);
        if ($xml === null || $xml === '') {
            return null;
        }
        // XXE guard: forbid network access, block external entity resolution, and
        // do not pass LIBXML_NOENT so entities are never substituted.
        $previous = libxml_use_internal_errors(true);
        libxml_set_external_entity_loader(static function () {
            return null;
        });
        $doc = new \DOMDocument();
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_set_external_entity_loader(null);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok ? $doc : null;
    }

    /**
     * Resolves the relationships (.rels) for a given part.
     *
     * @param string $partpath Zip path of the part whose rels are wanted.
     * @return array Map of relationship id to resolved zip path.
     */
    public function get_rels(string $partpath): array {
        $dir = dirname($partpath);
        $relspath = ($dir === '.' ? '' : $dir . '/') . '_rels/' . basename($partpath) . '.rels';
        $doc = $this->get_xml($relspath);
        $map = [];
        if ($doc === null) {
            return $map;
        }
        foreach ($doc->getElementsByTagNameNS(self::NS_PR, 'Relationship') as $rel) {
            $id = $rel->getAttribute('Id');
            $target = $rel->getAttribute('Target');
            if ($id === '' || $target === '') {
                continue;
            }
            // External targets (mode="External") point outside the package; skip.
            if (strcasecmp($rel->getAttribute('TargetMode'), 'External') === 0) {
                continue;
            }
            $map[$id] = self::resolve_path($dir, $target);
        }
        return $map;
    }

    /**
     * Returns the raw bytes of a media (or any) part.
     *
     * @param string $path Zip path of the part.
     * @return string|null The bytes, or null if the part is missing.
     */
    public function get_bytes(string $path): ?string {
        return $this->read_entry($path);
    }

    /**
     * Reads a package entry, enforcing per-part and aggregate uncompressed-size
     * caps before inflating so a compressed "zip bomb" cannot exhaust memory.
     *
     * @param string $path Zip path of the part.
     * @return string|null The bytes, or null if the part is missing.
     * @throws \moodle_exception If the part or the running total exceeds the caps.
     */
    private function read_entry(string $path): ?string {
        $stat = $this->zip->statName($path);
        if ($stat === false) {
            return null;
        }
        $declared = (int) ($stat['size'] ?? 0);
        if ($declared > self::MAX_PART_SIZE || $this->inflated + $declared > self::MAX_TOTAL_SIZE) {
            throw new \moodle_exception('errortoolarge', 'local_lessonimportpptx');
        }
        $data = $this->zip->getFromName($path);
        if ($data === false) {
            return null;
        }
        // Guard again against a header understating the real inflated size.
        $this->inflated += strlen($data);
        if (strlen($data) > self::MAX_PART_SIZE || $this->inflated > self::MAX_TOTAL_SIZE) {
            throw new \moodle_exception('errortoolarge', 'local_lessonimportpptx');
        }
        return $data;
    }

    /**
     * Whether a part exists in the package.
     *
     * @param string $path Zip path of the part.
     * @return bool True if present.
     */
    public function has(string $path): bool {
        return $this->zip->locateName($path) !== false;
    }

    /**
     * Slide width in EMU.
     *
     * @return int
     */
    public function slide_width(): int {
        return $this->slidewidth;
    }

    /**
     * Slide height in EMU.
     *
     * @return int
     */
    public function slide_height(): int {
        return $this->slideheight;
    }

    /**
     * Closes the underlying archive.
     *
     * @return void
     */
    public function close(): void {
        $this->zip->close();
    }

    /**
     * Builds a namespace-aware XPath for a package document.
     *
     * @param \DOMDocument $doc The document to query.
     * @return \DOMXPath The configured XPath instance.
     */
    public static function xpath(\DOMDocument $doc): \DOMXPath {
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('a', self::NS_A);
        $xpath->registerNamespace('p', self::NS_P);
        $xpath->registerNamespace('r', self::NS_R);
        return $xpath;
    }

    /**
     * Resolves a possibly-relative relationship target to a normalised zip path.
     *
     * @param string $basedir Directory of the part owning the relationship.
     * @param string $target The Target attribute from the .rels entry.
     * @return string The normalised zip path (no leading slash, "." / ".." collapsed).
     */
    private static function resolve_path(string $basedir, string $target): string {
        // Absolute package references start with a slash from the package root.
        if (str_starts_with($target, '/')) {
            $combined = ltrim($target, '/');
        } else {
            $combined = ($basedir === '.' ? '' : $basedir . '/') . $target;
        }
        $parts = [];
        foreach (explode('/', $combined) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }
        return implode('/', $parts);
    }
}
