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
 * Parses a single slide into positioned content blocks.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx\pptx;

/**
 * Walks one slide's shape tree and emits {@see block} value objects, detecting
 * the title placeholder and any section-divider panel along the way.
 */
class slide {
    /** @var int A section panel must begin within this many EMU of the left edge. */
    const PANEL_MAX_X_EMU = 200000;

    /** @var int A section panel must be at least this tall in EMU (near full height). */
    const PANEL_MIN_H_EMU = 5000000;

    /** @var int Standalone text this short (stripped) is treated as a decorative badge. */
    const BADGE_MAX_CHARS = 4;

    /** @var int Deepest DrawingML outline level (0-8); parsed levels are clamped to it. */
    const MAX_LIST_LEVEL = 8;

    /** @var int Sentinel offset for shapes with no explicit transform (sorts last). */
    const NO_OFFSET = 1000000000000;

    /** @var package The owning package (for rels/diagram resolution). */
    private package $package;

    /** @var string Zip path of this slide part. */
    private string $path;

    /** @var array Relationship id => resolved zip path for this slide. */
    private array $rels;

    /**
     * Constructor.
     *
     * @param package $package The open package.
     * @param string $path Zip path of the slide part.
     */
    public function __construct(package $package, string $path) {
        $this->package = $package;
        $this->path = $path;
        $this->rels = $package->get_rels($path);
    }

    /**
     * Parses the slide.
     *
     * @return \stdClass Object with properties:
     *                   - title (?string): the page title, or null;
     *                   - blocks (block[]): body blocks with the title removed;
     *                   - section (?\stdClass): {colour:string, panelright:?int} when the
     *                     slide is a section divider, otherwise null.
     */
    public function parse(): \stdClass {
        $doc = $this->package->get_xml($this->path);
        $result = (object) ['title' => null, 'blocks' => [], 'section' => null];
        if ($doc === null) {
            return $result;
        }
        $xpath = package::xpath($doc);
        $tree = $xpath->query('/p:sld/p:cSld/p:spTree')->item(0);
        if (!$tree instanceof \DOMElement) {
            return $result;
        }

        $blocks = [];
        $this->collect($tree, $xpath, $blocks);

        // The title placeholder becomes the page title and leaves the body.
        $title = null;
        $body = [];
        foreach ($blocks as $b) {
            if ($b->type === block::TYPE_TITLE && $title === null && $b->content !== '') {
                $title = $b->content;
            } else if ($b->type !== block::TYPE_TITLE) {
                $body[] = $b;
            }
        }

        $result->title = $title;
        $result->blocks = $body;
        $result->section = $this->detect_section($doc, $xpath);
        return $result;
    }

    /**
     * Recursively collects blocks from a shape-tree element.
     *
     * @param \DOMElement $el The container element (spTree or grpSp).
     * @param \DOMXPath $xpath Namespaced XPath over the slide document.
     * @param block[] $out Accumulator, passed by reference.
     * @param array|null $tf Coordinate transform inherited from enclosing groups.
     * @return void
     */
    private function collect(\DOMElement $el, \DOMXPath $xpath, array &$out, ?array $tf = null): void {
        foreach ($el->childNodes as $ch) {
            if (!$ch instanceof \DOMElement || $ch->namespaceURI !== package::NS_P) {
                continue;
            }
            switch ($ch->localName) {
                case 'pic':
                    $this->collect_picture($ch, $xpath, $out, $tf);
                    break;
                case 'sp':
                    $this->collect_shape($ch, $xpath, $out, $tf);
                    break;
                case 'graphicFrame':
                    $html = $this->smartart_html($ch, $xpath);
                    if ($html === null) {
                        $html = $this->table_html($ch, $xpath);
                    }
                    if ($html !== null) {
                        [$y, $x] = $this->offset($ch, $xpath, $tf);
                        $out[] = new block(block::TYPE_HTML, $y, $x, $html);
                    }
                    break;
                case 'grpSp':
                    // Children are positioned in the group's coordinate space; carry
                    // the composed transform down so they sort correctly on the slide.
                    $this->collect($ch, $xpath, $out, $this->group_transform($ch, $xpath, $tf));
                    break;
            }
        }
    }

    /**
     * Composes a group's coordinate transform with the one inherited from its
     * ancestors, mapping child offsets into slide coordinates.
     *
     * @param \DOMElement $grpsp The p:grpSp element.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @param array|null $parenttf The transform of the enclosing group, or null.
     * @return array|null The composed transform, or the parent's if this group has none.
     */
    private function group_transform(\DOMElement $grpsp, \DOMXPath $xpath, ?array $parenttf): ?array {
        $xfrm = $xpath->query('./p:grpSpPr/a:xfrm', $grpsp)->item(0);
        if (!$xfrm instanceof \DOMElement) {
            return $parenttf;
        }
        $off = $xpath->query('a:off', $xfrm)->item(0);
        $ext = $xpath->query('a:ext', $xfrm)->item(0);
        $choff = $xpath->query('a:chOff', $xfrm)->item(0);
        $chext = $xpath->query('a:chExt', $xfrm)->item(0);
        if (
            !$off instanceof \DOMElement || !$ext instanceof \DOMElement
                || !$choff instanceof \DOMElement || !$chext instanceof \DOMElement
        ) {
            return $parenttf;
        }
        $chextcx = (int) $chext->getAttribute('cx');
        $chextcy = (int) $chext->getAttribute('cy');
        $sx = $chextcx > 0 ? (int) $ext->getAttribute('cx') / $chextcx : 1.0;
        $sy = $chextcy > 0 ? (int) $ext->getAttribute('cy') / $chextcy : 1.0;
        $localox = (int) $off->getAttribute('x') - (int) $choff->getAttribute('x') * $sx;
        $localoy = (int) $off->getAttribute('y') - (int) $choff->getAttribute('y') * $sy;

        if ($parenttf === null) {
            return ['ox' => $localox, 'oy' => $localoy, 'sx' => $sx, 'sy' => $sy];
        }
        return [
            'ox' => $parenttf['ox'] + $localox * $parenttf['sx'],
            'oy' => $parenttf['oy'] + $localoy * $parenttf['sy'],
            'sx' => $sx * $parenttf['sx'],
            'sy' => $sy * $parenttf['sy'],
        ];
    }

    /**
     * Collects a picture block, resolving its media file via the slide rels.
     *
     * @param \DOMElement $pic The p:pic element.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @param block[] $out Accumulator, passed by reference.
     * @param array|null $tf Coordinate transform inherited from enclosing groups.
     * @return void
     */
    private function collect_picture(\DOMElement $pic, \DOMXPath $xpath, array &$out, ?array $tf = null): void {
        $blip = $xpath->query('.//a:blip', $pic)->item(0);
        if (!$blip instanceof \DOMElement) {
            return;
        }
        // The r:embed reference already points at PowerPoint's raster fallback for
        // vector art, which is what browsers can render; do not chase svgBlip in extLst.
        $rid = $blip->getAttributeNS(package::NS_R, 'embed');
        if ($rid === '' || !isset($this->rels[$rid])) {
            return;
        }
        [$y, $x] = $this->offset($pic, $xpath, $tf);
        $out[] = new block(block::TYPE_IMAGE, $y, $x, $this->rels[$rid]);
    }

    /**
     * Collects a text shape, or the title, dropping decorative badges.
     *
     * @param \DOMElement $sp The p:sp element.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @param block[] $out Accumulator, passed by reference.
     * @param array|null $tf Coordinate transform inherited from enclosing groups.
     * @return void
     */
    private function collect_shape(\DOMElement $sp, \DOMXPath $xpath, array &$out, ?array $tf = null): void {
        // A footer, slide-number or date placeholder is page furniture repeated on
        // every slide, not content. Skip it before recovering any fill, so an
        // image-filled footer is left out as well as its text.
        if ($this->is_furniture($sp, $xpath)) {
            return;
        }
        [$y, $x] = $this->offset($sp, $xpath, $tf);
        // A shape can carry an image as a picture fill rather than as a <p:pic>;
        // recover it for title and ordinary shapes alike (a title placeholder may
        // still have a picture fill), so styled frames and placeholders are not lost.
        $this->collect_shape_fill($sp, $xpath, $out, $y, $x);
        if ($this->is_title($sp, $xpath)) {
            $text = $this->raw_text($sp, $xpath);
            if ($text !== '') {
                $out[] = new block(block::TYPE_TITLE, $y, $x, $text);
            }
            return;
        }
        $paras = $this->paragraphs($sp, $xpath);
        if (empty($paras)) {
            return;
        }
        // Drop a lone, very short label (e.g. a corner "A-T" badge): furniture, not content.
        if (count($paras) === 1 && \core_text::strlen(trim(strip_tags($paras[0]['text']))) <= self::BADGE_MAX_CHARS) {
            return;
        }
        $block = new block(block::TYPE_TEXT, $y, $x, array_column($paras, 'text'));
        $block->levels = array_column($paras, 'level');
        // Whether each paragraph suppresses its bullet is kept per paragraph, so a
        // box that mixes an intro line with a bulleted list renders the intro as
        // prose and only the bulleted paragraphs as a list.
        $block->nobullets = array_column($paras, 'nobullet');
        $out[] = $block;
    }

    /**
     * Emits an image block for a shape whose fill is an embedded picture.
     *
     * @param \DOMElement $sp The p:sp element.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @param block[] $out Accumulator, passed by reference.
     * @param int $y The shape's vertical offset in EMU.
     * @param int $x The shape's horizontal offset in EMU.
     * @return void
     */
    private function collect_shape_fill(\DOMElement $sp, \DOMXPath $xpath, array &$out, int $y, int $x): void {
        $blip = $xpath->query('./p:spPr/a:blipFill/a:blip', $sp)->item(0);
        if (!$blip instanceof \DOMElement) {
            return;
        }
        $rid = $blip->getAttributeNS(package::NS_R, 'embed');
        if ($rid === '' || !isset($this->rels[$rid])) {
            return;
        }
        $out[] = new block(block::TYPE_IMAGE, $y, $x, $this->rels[$rid]);
    }

    /**
     * Returns the (y, x) offset of a shape in EMU, or a large sentinel if absent.
     *
     * @param \DOMElement $el The shape element.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @param array|null $tf Coordinate transform inherited from enclosing groups.
     * @return array The [y, x] pair.
     */
    private function offset(\DOMElement $el, \DOMXPath $xpath, ?array $tf = null): array {
        $off = $xpath->query('.//a:off', $el)->item(0);
        if ($off instanceof \DOMElement && $off->getAttribute('y') !== '') {
            $y = (int) $off->getAttribute('y');
            $x = (int) $off->getAttribute('x');
            if ($tf !== null) {
                $y = (int) round($tf['oy'] + $y * $tf['sy']);
                $x = (int) round($tf['ox'] + $x * $tf['sx']);
            }
            return [$y, $x];
        }
        return [self::NO_OFFSET, self::NO_OFFSET];
    }

    /**
     * Whether a shape is the slide's title placeholder.
     *
     * @param \DOMElement $sp The p:sp element.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @return bool True for title/ctrTitle placeholders.
     */
    private function is_title(\DOMElement $sp, \DOMXPath $xpath): bool {
        $ph = $xpath->query('.//p:nvSpPr/p:nvPr/p:ph', $sp)->item(0);
        return $ph instanceof \DOMElement && in_array($ph->getAttribute('type'), ['title', 'ctrTitle'], true);
    }

    /**
     * Whether a shape is a footer, slide-number or date placeholder — page
     * furniture that repeats on every slide rather than slide content.
     *
     * @param \DOMElement $sp The p:sp element.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @return bool True for ftr/sldNum/dt placeholders.
     */
    private function is_furniture(\DOMElement $sp, \DOMXPath $xpath): bool {
        $ph = $xpath->query('.//p:nvSpPr/p:nvPr/p:ph', $sp)->item(0);
        return $ph instanceof \DOMElement && in_array($ph->getAttribute('type'), ['ftr', 'sldNum', 'dt'], true);
    }

    /**
     * Extracts paragraphs from a text shape as escaped HTML fragments, keeping
     * each paragraph's indent level and whether it suppresses its bullet.
     *
     * Each a:p becomes one entry; line breaks (a:br) become "\n" within the
     * entry; bold runs become <strong>. All run text is HTML-escaped.
     *
     * @param \DOMElement $sp The shape element containing a txBody.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @return array[] Entries of ['text'=>string, 'level'=>int, 'nobullet'=>bool].
     */
    private function paragraphs(\DOMElement $sp, \DOMXPath $xpath): array {
        $out = [];
        $liststyle = $this->list_style_bullets($sp, $xpath);
        foreach ($xpath->query('.//a:p', $sp) as $p) {
            $buf = '';
            foreach ($p->childNodes as $node) {
                if (!$node instanceof \DOMElement || $node->namespaceURI !== package::NS_A) {
                    continue;
                }
                if ($node->localName === 'br') {
                    $buf .= "\n";
                } else if ($node->localName === 'r') {
                    $t = $xpath->query('a:t', $node)->item(0);
                    if (!$t instanceof \DOMElement || $t->textContent === '') {
                        continue;
                    }
                    $text = s($t->textContent);
                    $rpr = $xpath->query('a:rPr', $node)->item(0);
                    if ($rpr instanceof \DOMElement && $rpr->getAttribute('b') === '1') {
                        $text = '<strong>' . $text . '</strong>';
                    }
                    $buf .= $text;
                }
            }
            $line = trim($buf);
            if ($line === '') {
                continue;
            }
            $ppr = $xpath->query('a:pPr', $p)->item(0);
            $level = 0;
            $nobullet = null;
            if ($ppr instanceof \DOMElement) {
                // DrawingML outlines allow levels 0-8; clamp so a crafted lvl (the
                // uploaded XML is not schema-validated) cannot drive deep nesting.
                $level = min(self::MAX_LIST_LEVEL, max(0, (int) $ppr->getAttribute('lvl')));
                $nobullet = self::bullet_state($ppr, $xpath);
            }
            if ($nobullet === null) {
                // The paragraph itself is silent about bullets: fall back to the
                // shape's own list style for this level. Styles inherited from the
                // slide layout or master are not resolved; that unknown state reads
                // as bulleted, matching PowerPoint's usual placeholder default.
                $nobullet = $liststyle[$level] ?? false;
            }
            $out[] = ['text' => $line, 'level' => $level, 'nobullet' => $nobullet];
        }
        return $out;
    }

    /**
     * Reads the bullet states declared by a shape's own txBody list style.
     *
     * @param \DOMElement $sp The shape element.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @return array Map of indent level (0-8) to bool "bullet suppressed";
     *               levels the style leaves undecided are absent.
     */
    private function list_style_bullets(\DOMElement $sp, \DOMXPath $xpath): array {
        $states = [];
        $lststyle = $xpath->query('./p:txBody/a:lstStyle', $sp)->item(0);
        if (!$lststyle instanceof \DOMElement) {
            return $states;
        }
        foreach ($lststyle->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->namespaceURI !== package::NS_A) {
                continue;
            }
            if (!preg_match('/^lvl([1-9])pPr$/', $child->localName, $m)) {
                continue;
            }
            $state = self::bullet_state($child, $xpath);
            if ($state !== null) {
                $states[(int) $m[1] - 1] = $state;
            }
        }
        return $states;
    }

    /**
     * Reads the explicit bullet state carried by a paragraph-properties element.
     *
     * @param \DOMElement $props A paragraph-properties element (a:pPr or a:lvlNpPr).
     * @param \DOMXPath $xpath Namespaced XPath.
     * @return bool|null True if bullets are switched off (a:buNone), false if a
     *                   bullet is set (a:buChar / a:buAutoNum), null if unspecified.
     */
    private static function bullet_state(\DOMElement $props, \DOMXPath $xpath): ?bool {
        if ($xpath->query('a:buNone', $props)->item(0) instanceof \DOMElement) {
            return true;
        }
        if (
            $xpath->query('a:buChar', $props)->item(0) instanceof \DOMElement
                || $xpath->query('a:buAutoNum', $props)->item(0) instanceof \DOMElement
        ) {
            return false;
        }
        return null;
    }

    /**
     * Extracts the raw, unescaped plain text of a shape (used for titles, which
     * are stored as plain text and escaped by Moodle on display).
     *
     * @param \DOMElement $sp The shape element.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @return string Whitespace-collapsed plain text.
     */
    private function raw_text(\DOMElement $sp, \DOMXPath $xpath): string {
        $paras = [];
        foreach ($xpath->query('.//a:p', $sp) as $p) {
            $parts = [];
            foreach ($xpath->query('.//a:t', $p) as $t) {
                if ($t->textContent !== '') {
                    $parts[] = $t->textContent;
                }
            }
            $line = trim(implode('', $parts));
            if ($line !== '') {
                $paras[] = $line;
            }
        }
        return trim(preg_replace('/\s+/u', ' ', implode(' ', $paras)));
    }

    /**
     * Recovers SmartArt text as a flat unordered list.
     *
     * @param \DOMElement $gf The p:graphicFrame element.
     * @param \DOMXPath $xpath Namespaced XPath over the slide.
     * @return string|null The <ul> HTML, or null if this frame is not SmartArt.
     */
    private function smartart_html(\DOMElement $gf, \DOMXPath $xpath): ?string {
        $rel = $xpath->query('.//*[@r:dm]', $gf)->item(0);
        if (!$rel instanceof \DOMElement) {
            return null;
        }
        $dm = $rel->getAttributeNS(package::NS_R, 'dm');
        if ($dm === '' || !isset($this->rels[$dm])) {
            return null;
        }
        $datapath = $this->rels[$dm];
        $candidates = [];
        if (preg_match('/(\d+)/', basename($datapath), $m)) {
            $candidates[] = 'ppt/diagrams/drawing' . $m[1] . '.xml';
        }
        $candidates[] = $datapath;

        foreach ($candidates as $candidate) {
            $doc = $this->package->get_xml($candidate);
            if ($doc === null) {
                continue;
            }
            $items = [];
            foreach ($doc->getElementsByTagNameNS(package::NS_A, 't') as $t) {
                $v = trim($t->textContent);
                if ($v !== '' && (empty($items) || end($items) !== $v)) {
                    $items[] = $v;
                }
            }
            if (!empty($items)) {
                $lis = '';
                foreach ($items as $v) {
                    $lis .= '<li>' . s($v) . '</li>';
                }
                return '<ul>' . $lis . '</ul>';
            }
        }
        return null;
    }

    /**
     * Renders a table graphicFrame as an HTML table (first row as headers).
     *
     * @param \DOMElement $gf The p:graphicFrame element.
     * @param \DOMXPath $xpath Namespaced XPath over the slide.
     * @return string|null The <table> HTML, or null if this frame is not a table.
     */
    private function table_html(\DOMElement $gf, \DOMXPath $xpath): ?string {
        $tbl = $xpath->query('.//a:tbl', $gf)->item(0);
        if (!$tbl instanceof \DOMElement) {
            return null;
        }
        $rows = [];
        foreach ($xpath->query('a:tr', $tbl) as $tr) {
            $cells = [];
            foreach ($xpath->query('a:tc', $tr) as $tc) {
                // Concatenate runs within a paragraph verbatim (mixed formatting
                // must not introduce spaces); separate distinct paragraphs by a space.
                $paras = [];
                foreach ($xpath->query('.//a:p', $tc) as $p) {
                    $runs = '';
                    foreach ($xpath->query('.//a:t', $p) as $t) {
                        $runs .= $t->textContent;
                    }
                    if (trim($runs) !== '') {
                        $paras[] = s($runs);
                    }
                }
                $cells[] = implode(' ', $paras);
            }
            if (!empty($cells)) {
                $rows[] = $cells;
            }
        }
        if (empty($rows)) {
            return null;
        }
        $head = '';
        foreach ($rows[0] as $c) {
            $head .= '<th>' . $c . '</th>';
        }
        $body = '';
        foreach (array_slice($rows, 1) as $r) {
            $cells = '';
            foreach ($r as $c) {
                $cells .= '<td>' . $c . '</td>';
            }
            $body .= '<tr>' . $cells . '</tr>';
        }
        return '<table class="table"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }

    /**
     * Detects a section-divider panel: a full-height, solid-filled rectangle
     * pinned to the left edge. Geometry — not a hardcoded colour — is the signal;
     * the slide's section-header layout is used as a secondary signal.
     *
     * @param \DOMDocument $doc The slide document.
     * @param \DOMXPath $xpath Namespaced XPath over the slide.
     * @return \stdClass|null {colour:?string, panelright:?int} for a divider, else null.
     */
    private function detect_section(\DOMDocument $doc, \DOMXPath $xpath): ?\stdClass {
        // A side panel occupies a bounded fraction of the slide width; a rectangle
        // wider than this is a full-slide background, not a divider plate.
        $maxpanelwidth = (int) ($this->package->slide_width() / 2);
        foreach ($xpath->query('/p:sld/p:cSld/p:spTree/p:sp') as $sp) {
            $fill = $xpath->query('.//p:spPr/a:solidFill/a:srgbClr', $sp)->item(0);
            $off = $xpath->query('.//p:spPr/a:xfrm/a:off', $sp)->item(0);
            $ext = $xpath->query('.//p:spPr/a:xfrm/a:ext', $sp)->item(0);
            if (!$fill instanceof \DOMElement || !$off instanceof \DOMElement || !$ext instanceof \DOMElement) {
                continue;
            }
            $x = (int) $off->getAttribute('x');
            $cx = (int) $ext->getAttribute('cx');
            $cy = (int) $ext->getAttribute('cy');
            if ($x <= self::PANEL_MAX_X_EMU && $cy >= self::PANEL_MIN_H_EMU && $cx <= $maxpanelwidth) {
                $val = strtolower($fill->getAttribute('val'));
                $colour = preg_match('/^[0-9a-f]{6}$/', $val) ? '#' . $val : null;
                return (object) ['colour' => $colour, 'panelright' => $x + $cx];
            }
        }

        // Secondary signal: the slide uses the section-header layout.
        if ($this->uses_section_layout()) {
            return (object) ['colour' => null, 'panelright' => null];
        }
        return null;
    }

    /**
     * Whether this slide is based on PowerPoint's section-header layout.
     *
     * @return bool True if the referenced slide layout has type "secHead".
     */
    private function uses_section_layout(): bool {
        foreach ($this->rels as $target) {
            if (!preg_match('#slideLayout\d+\.xml$#', $target)) {
                continue;
            }
            $doc = $this->package->get_xml($target);
            if ($doc === null) {
                continue;
            }
            $root = $doc->documentElement;
            if ($root instanceof \DOMElement && $root->getAttribute('type') === 'secHead') {
                return true;
            }
        }
        return false;
    }
}
