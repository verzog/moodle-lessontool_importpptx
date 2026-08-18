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

    /** @var float Picture area (as a fraction of the slide) that marks a slide as photo-led. */
    const PHOTO_AREA_FRACTION = 0.06;

    /** @var package The owning package (for rels/diagram resolution). */
    private package $package;

    /** @var string Zip path of this slide part. */
    private string $path;

    /** @var array Relationship id => resolved zip path for this slide. */
    private array $rels;

    /** @var theme|null Lazily-loaded scheme-colour resolver for this slide. */
    private ?theme $theme = null;

    /**
     * @var array<string,array<int,int>>|null Master default font sizes, lazily traced:
     *      placeholder category (title|body|other) => outline level => size in 1/100 pt.
     */
    private ?array $textstyles = null;

    /** @var \DOMXPath|null Cached XPath over this slide's layout part (null once tried and absent). */
    private ?\DOMXPath $layoutxpath = null;

    /** @var bool Whether the layout has been resolved, so an absent layout is not retried. */
    private bool $layouttried = false;

    /** @var shape[] Vector diagram shapes collected from this slide. */
    private array $vectors = [];

    /** @var block[] Text blocks that mirror a vector shape's label (dropped if a diagram forms). */
    private array $vectortextblocks = [];

    /** @var bool Whether this slide contains a photo large enough to lead the page. */
    private bool $hasphoto = false;

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
        $this->vectors = [];
        $this->vectortextblocks = [];
        $this->hasphoto = false;
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

        $body = $this->apply_diagram($body);

        $result->title = $title;
        $result->blocks = $body;
        $result->section = $this->detect_section($doc, $xpath);
        return $result;
    }

    /**
     * When the collected vector shapes form a diagram, replaces their flattened
     * text blocks with a single reconstructed SVG figure; otherwise leaves the
     * body untouched so ordinary slides behave exactly as before.
     *
     * @param block[] $body The body blocks in collection order.
     * @return block[] The body blocks, possibly with a diagram figure spliced in.
     */
    private function apply_diagram(array $body): array {
        $this->vectors = array_values(array_filter($this->vectors, [self::class, 'is_visible_shape']));
        if (!$this->qualifies_as_diagram()) {
            return $body;
        }
        $svg = svg_builder::build($this->vectors);
        if ($svg === '') {
            return $body;
        }

        // Drop the text blocks that duplicate the diagram shapes' labels, then
        // place the figure where the top-left shape sat so it flows in order.
        $drop = [];
        foreach ($this->vectortextblocks as $b) {
            $drop[spl_object_id($b)] = true;
        }
        $kept = [];
        foreach ($body as $b) {
            if (!isset($drop[spl_object_id($b)])) {
                $kept[] = $b;
            }
        }
        $top = $this->vectors[0];
        foreach ($this->vectors as $v) {
            if ($v->y < $top->y || ($v->y === $top->y && $v->x < $top->x)) {
                $top = $v;
            }
        }
        $kept[] = new block(block::TYPE_HTML, $top->y, $top->x, $svg);
        return $kept;
    }

    /**
     * Whether the collected vector shapes look like an intentional, labelled
     * diagram — two or more captioned boxes.
     *
     * Requiring captioned boxes is deliberately strict: unlabelled arrows, lines,
     * circles and rotated outlines are almost always annotations drawn over a
     * photo, and reconstructing those without the photo produces noise. A genuine
     * process/flow diagram (the case this feature targets) labels its boxes.
     *
     * @return bool True if a diagram figure should be reconstructed.
     */
    private function qualifies_as_diagram(): bool {
        // A photo-led slide keeps its editable text and image; stray annotation
        // shapes on it are not promoted into a diagram figure.
        if ($this->hasphoto) {
            return false;
        }
        $captioned = 0;
        foreach ($this->vectors as $v) {
            if ($v->kind !== shape::KIND_ARROW && $v->kind !== shape::KIND_LINE && trim($v->text) !== '') {
                $captioned++;
            }
        }
        return $captioned >= 2;
    }

    /**
     * Whether a shape would be visible on a white page: arrows and connectors
     * always are; a text-free box that is white (or unfilled) with no contrasting
     * outline is invisible clutter and is dropped from the diagram.
     *
     * @param shape $s The shape to test.
     * @return bool True if the shape should be drawn.
     */
    private static function is_visible_shape(shape $s): bool {
        if ($s->kind === shape::KIND_ARROW || $s->kind === shape::KIND_LINE) {
            return true;
        }
        if (trim($s->text) !== '') {
            return true;
        }
        $white = static function (?string $c): bool {
            return $c === null || strtoupper($c) === '#FFFFFF';
        };
        return !($white($s->fill) && $white($s->line));
    }

    /**
     * Returns (and lazily builds) the scheme-colour resolver for this slide.
     *
     * @return theme The colour resolver.
     */
    private function theme(): theme {
        if ($this->theme === null) {
            $this->theme = new theme($this->package, $this->path);
        }
        return $this->theme;
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
                        [$y, $x, $cy, $cx] = $this->geometry($ch, $xpath, $tf);
                        $htmlblock = new block(block::TYPE_HTML, $y, $x, $html);
                        $htmlblock->cy = $cy;
                        $htmlblock->cx = $cx;
                        $out[] = $htmlblock;
                    }
                    break;
                case 'cxnSp':
                    $this->collect_connector($ch, $xpath, $tf);
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
        [$y, $x, $cy, $cx] = $this->geometry($pic, $xpath, $tf);
        $picblock = new block(block::TYPE_IMAGE, $y, $x, $this->rels[$rid]);
        $picblock->cy = $cy;
        $picblock->cx = $cx;
        $picblock->widthpct = $this->width_percent($cx);
        $out[] = $picblock;
        $this->note_photo($pic, $xpath, $tf);
    }

    /**
     * Flags the slide as photo-dominated when a picture covers a meaningful area,
     * so a stray annotation arrow or box on a photo slide does not turn the page
     * into a reconstructed diagram. Small logos and icons do not count.
     *
     * @param \DOMElement $pic The p:pic element.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @param array|null $tf Coordinate transform inherited from enclosing groups.
     * @return void
     */
    private function note_photo(\DOMElement $pic, \DOMXPath $xpath, ?array $tf): void {
        $ext = $xpath->query('.//a:ext', $pic)->item(0);
        if (!$ext instanceof \DOMElement) {
            return;
        }
        $cx = (int) $ext->getAttribute('cx');
        $cy = (int) $ext->getAttribute('cy');
        if ($tf !== null) {
            $cx = (int) round($cx * $tf['sx']);
            $cy = (int) round($cy * $tf['sy']);
        }
        $slidearea = $this->package->slide_width() * $this->package->slide_height();
        if ($slidearea > 0 && ($cx * $cy) >= $slidearea * self::PHOTO_AREA_FRACTION) {
            $this->hasphoto = true;
        }
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
        [$y, $x, $cy, $cx] = $this->geometry($sp, $xpath, $tf);
        // A shape can carry an image as a picture fill rather than as a <p:pic>;
        // recover it for title and ordinary shapes alike (a title placeholder may
        // still have a picture fill), so styled frames and placeholders are not lost.
        $this->collect_shape_fill($sp, $xpath, $out, $y, $x, $cy, $cx);
        if ($this->is_title($sp, $xpath)) {
            $text = $this->raw_text($sp, $xpath);
            if ($text !== '') {
                $title = new block(block::TYPE_TITLE, $y, $x, $text);
                $title->cy = $cy;
                $title->cx = $cx;
                $out[] = $title;
            }
            return;
        }
        $paras = $this->paragraphs($sp, $xpath, $this->text_category($sp, $xpath));
        if (empty($paras)) {
            // A drawn but text-free shape (an arrow, a plain box) is only useful as
            // part of a reconstructed diagram; keep it as a vector, never as text.
            $this->collect_vector($sp, $xpath, $tf, '');
            return;
        }

        // A filled or outlined shape carrying this text may be a diagram node. Record
        // it as a vector (with its label, however short) before the badge heuristic,
        // so short captions such as "Yes"/"No" are not discarded from a diagram.
        $isvector = $this->collect_vector($sp, $xpath, $tf, $this->plain_lines($paras));

        // Drop a lone, very short label on a plain text shape (e.g. a corner "A-T"
        // badge): furniture, not content. Drawn diagram nodes are exempt.
        if (
            !$isvector && count($paras) === 1
                && \core_text::strlen(trim(strip_tags($paras[0]['text']))) <= self::BADGE_MAX_CHARS
        ) {
            return;
        }

        $block = new block(block::TYPE_TEXT, $y, $x, array_column($paras, 'text'));
        $block->levels = array_column($paras, 'level');
        // Whether each paragraph suppresses its bullet is kept per paragraph, so a
        // box that mixes an intro line with a bulleted list renders the intro as
        // prose and only the bulleted paragraphs as a list.
        $block->nobullets = array_column($paras, 'nobullet');
        $block->cy = $cy;
        $block->cx = $cx;
        $out[] = $block;

        // When the shape is a diagram node, this text block mirrors its label and is
        // dropped in favour of the reconstructed figure if the slide is a diagram.
        if ($isvector) {
            $this->vectortextblocks[] = $block;
        }
    }

    /**
     * Parses a shape as a vector diagram primitive and records it, when the shape
     * is a drawn box, ellipse or block arrow (not a placeholder or plain text box).
     *
     * @param \DOMElement $sp The p:sp element.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @param array|null $tf Coordinate transform inherited from enclosing groups.
     * @param string $text The shape's plain, newline-separated label text.
     * @return bool True if the shape was recorded as a vector.
     */
    private function collect_vector(\DOMElement $sp, \DOMXPath $xpath, ?array $tf, string $text): bool {
        $shape = $this->vector_candidate($sp, $xpath, $tf);
        if ($shape === null) {
            return false;
        }
        $shape->text = $text;
        $this->vectors[] = $shape;
        return true;
    }

    /**
     * Emits an image block for a shape whose fill is an embedded picture.
     *
     * @param \DOMElement $sp The p:sp element.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @param block[] $out Accumulator, passed by reference.
     * @param int $y The shape's vertical offset in EMU.
     * @param int $x The shape's horizontal offset in EMU.
     * @param int $cy The shape's height in EMU (0 when unknown).
     * @param int $cx The shape's width in EMU (0 when unknown).
     * @return void
     */
    private function collect_shape_fill(
        \DOMElement $sp,
        \DOMXPath $xpath,
        array &$out,
        int $y,
        int $x,
        int $cy = 0,
        int $cx = 0
    ): void {
        $blip = $xpath->query('./p:spPr/a:blipFill/a:blip', $sp)->item(0);
        if (!$blip instanceof \DOMElement) {
            return;
        }
        $rid = $blip->getAttributeNS(package::NS_R, 'embed');
        if ($rid === '' || !isset($this->rels[$rid])) {
            return;
        }
        $fill = new block(block::TYPE_IMAGE, $y, $x, $this->rels[$rid]);
        $fill->cy = $cy;
        $fill->cx = $cx;
        $fill->widthpct = $this->width_percent($cx);
        $out[] = $fill;
        // A large picture stored as a shape fill (rather than a <p:pic>) still
        // makes the slide photo-led, which suppresses diagram reconstruction.
        $this->note_photo($sp, $xpath, null);
    }

    /**
     * Collects a straight connector (line) as a vector diagram primitive.
     *
     * @param \DOMElement $cxn The p:cxnSp element.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @param array|null $tf Coordinate transform inherited from enclosing groups.
     * @return void
     */
    private function collect_connector(\DOMElement $cxn, \DOMXPath $xpath, ?array $tf = null): void {
        $geom = $this->geom_with_tf($cxn, $xpath, $tf);
        if ($geom === null) {
            return;
        }
        // A connector explicitly set to no line is an invisible layout/alignment
        // guide in PowerPoint; do not draw it into the reconstructed diagram.
        if ($xpath->query('./p:spPr/a:ln/a:noFill', $cxn)->item(0) instanceof \DOMElement) {
            return;
        }
        [$x, $y, $cx, $cy] = $geom;
        $shape = new shape(shape::KIND_LINE, $x, $y, $cx, $cy);
        [$shape->line, $shape->linewidth] = $this->resolve_line($cxn, $xpath);
        if ($shape->line === null) {
            $shape->line = '#000000';
        }
        $xfrm = $xpath->query('./p:spPr/a:xfrm', $cxn)->item(0);
        if ($xfrm instanceof \DOMElement) {
            $shape->fliph = $xfrm->getAttribute('flipH') === '1';
            $shape->flipv = $xfrm->getAttribute('flipV') === '1';
        }
        $this->vectors[] = $shape;
    }

    /**
     * Parses a shape into a {@see shape} value object when it is a drawn diagram
     * primitive: a block arrow, or a filled/outlined box or ellipse. Placeholders,
     * plain (unfilled) text boxes, full-slide backgrounds and the section panel
     * are rejected so only genuine diagram parts are reconstructed.
     *
     * @param \DOMElement $sp The p:sp element.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @param array|null $tf Coordinate transform inherited from enclosing groups.
     * @return shape|null The parsed shape, or null if it is not a diagram primitive.
     */
    private function vector_candidate(\DOMElement $sp, \DOMXPath $xpath, ?array $tf): ?shape {
        if ($xpath->query('.//p:nvSpPr/p:nvPr/p:ph', $sp)->item(0) instanceof \DOMElement) {
            return null;
        }
        // Accept preset geometry, and custom geometry as a bounding-box rectangle
        // (freeform and edited shapes) so a labelled node is not silently dropped.
        $prstel = $xpath->query('./p:spPr/a:prstGeom', $sp)->item(0);
        $custgeom = $xpath->query('./p:spPr/a:custGeom', $sp)->item(0);
        if (!$prstel instanceof \DOMElement && !$custgeom instanceof \DOMElement) {
            return null;
        }
        $geom = $this->geom_with_tf($sp, $xpath, $tf);
        if ($geom === null) {
            return null;
        }
        [$x, $y, $cx, $cy] = $geom;
        if ($cx <= 0 || $cy <= 0) {
            return null;
        }
        // A near-full-slide rectangle is a background, and a tall left-edge plate is
        // the section panel handled elsewhere; neither is a diagram part.
        if (
            $cx >= (int) ($this->package->slide_width() * 0.85)
                && $cy >= (int) ($this->package->slide_height() * 0.85)
        ) {
            return null;
        }
        if ($x <= self::PANEL_MAX_X_EMU && $cy >= self::PANEL_MIN_H_EMU) {
            return null;
        }

        $prst = $prstel instanceof \DOMElement ? $prstel->getAttribute('prst') : 'rect';
        [$kind, $arrow] = $this->classify_geometry($prst);

        $fill = $this->resolve_fill($sp, $xpath);
        [$line, $linewidth] = $this->resolve_line($sp, $xpath);
        $styled = $xpath->query('./p:style', $sp)->item(0) instanceof \DOMElement;
        // A plain rectangle with no fill, outline or shape style is a text box, not
        // a diagram node; leave it to the text path. Arrows always count.
        if ($kind !== shape::KIND_ARROW && $fill === null && $line === null && !$styled) {
            return null;
        }

        $shape = new shape($kind, $x, $y, $cx, $cy);
        $shape->arrow = $arrow;
        $shape->fill = $fill;
        $shape->line = $line;
        $shape->linewidth = $linewidth;
        $xfrm = $xpath->query('./p:spPr/a:xfrm', $sp)->item(0);
        if ($xfrm instanceof \DOMElement) {
            $rot = (int) $xfrm->getAttribute('rot');
            $shape->rotation = (($rot / 60000) % 360 + 360) % 360;
            $shape->fliph = $xfrm->getAttribute('flipH') === '1';
            $shape->flipv = $xfrm->getAttribute('flipV') === '1';
        }
        return $shape;
    }

    /**
     * Maps a DrawingML preset-geometry name to a shape kind and arrow direction.
     *
     * @param string $prst The prstGeom preset name (e.g. "roundRect", "rightArrow").
     * @return array A [kind, arrowdirection] pair.
     */
    private function classify_geometry(string $prst): array {
        // Only the four single-direction block arrows carry a reliable direction.
        // Multi-directional and bent arrows (leftRightArrow, bentArrow, uturnArrow …)
        // are drawn as their bounding box rather than given a false rightward point.
        if (preg_match('/^(right|left|up|down)Arrow$/', $prst, $m)) {
            return [shape::KIND_ARROW, $m[1]];
        }
        if ($prst === 'ellipse' || $prst === 'oval') {
            return [shape::KIND_ELLIPSE, 'right'];
        }
        if (stripos($prst, 'round') !== false || stripos($prst, 'snip') !== false || $prst === 'plaque') {
            return [shape::KIND_ROUNDRECT, 'right'];
        }
        return [shape::KIND_RECT, 'right'];
    }

    /**
     * Returns a shape's [x, y, cx, cy] geometry in EMU, applying any group
     * transform, or null when the shape has no explicit transform.
     *
     * @param \DOMElement $el The shape or connector element.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @param array|null $tf Coordinate transform inherited from enclosing groups.
     * @return int[]|null The [x, y, cx, cy] geometry, or null.
     */
    private function geom_with_tf(\DOMElement $el, \DOMXPath $xpath, ?array $tf): ?array {
        $off = $xpath->query('./p:spPr/a:xfrm/a:off', $el)->item(0);
        $ext = $xpath->query('./p:spPr/a:xfrm/a:ext', $el)->item(0);
        if (!$off instanceof \DOMElement || !$ext instanceof \DOMElement) {
            return null;
        }
        $x = (int) $off->getAttribute('x');
        $y = (int) $off->getAttribute('y');
        $cx = (int) $ext->getAttribute('cx');
        $cy = (int) $ext->getAttribute('cy');
        if ($tf !== null) {
            $x = (int) round($tf['ox'] + $x * $tf['sx']);
            $y = (int) round($tf['oy'] + $y * $tf['sy']);
            $cx = (int) round($cx * $tf['sx']);
            $cy = (int) round($cy * $tf['sy']);
        }
        return [$x, $y, $cx, $cy];
    }

    /**
     * Resolves a shape's fill colour from its direct fill or its shape style.
     *
     * @param \DOMElement $sp The shape element.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @return string|null The #RRGGBB fill, or null for no fill.
     */
    private function resolve_fill(\DOMElement $sp, \DOMXPath $xpath): ?string {
        if ($xpath->query('./p:spPr/a:noFill', $sp)->item(0) instanceof \DOMElement) {
            return null;
        }
        $direct = $xpath->query('./p:spPr/a:solidFill/*', $sp)->item(0);
        if ($direct instanceof \DOMElement) {
            return $this->colour_of($direct);
        }
        $ref = $xpath->query('./p:style/a:fillRef', $sp)->item(0);
        if ($ref instanceof \DOMElement && $ref->getAttribute('idx') !== '0') {
            $clr = $xpath->query('a:srgbClr | a:schemeClr', $ref)->item(0);
            if ($clr instanceof \DOMElement) {
                return $this->colour_of($clr);
            }
        }
        return null;
    }

    /**
     * Resolves a shape or connector outline colour and width.
     *
     * @param \DOMElement $el The shape or connector element.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @return array A [#RRGGBB|null colour, int widthemu] pair.
     */
    private function resolve_line(\DOMElement $el, \DOMXPath $xpath): array {
        $ln = $xpath->query('./p:spPr/a:ln', $el)->item(0);
        if ($ln instanceof \DOMElement) {
            if ($xpath->query('a:noFill', $ln)->item(0) instanceof \DOMElement) {
                return [null, 0];
            }
            $width = (int) $ln->getAttribute('w');
            $clr = $xpath->query('a:solidFill/*', $ln)->item(0);
            if ($clr instanceof \DOMElement) {
                return [$this->colour_of($clr), $width];
            }
        }
        $ref = $xpath->query('./p:style/a:lnRef', $el)->item(0);
        if ($ref instanceof \DOMElement && $ref->getAttribute('idx') !== '0') {
            $clr = $xpath->query('a:srgbClr | a:schemeClr', $ref)->item(0);
            if ($clr instanceof \DOMElement) {
                return [$this->colour_of($clr), 0];
            }
        }
        return [null, 0];
    }

    /**
     * Resolves a colour element (srgbClr or schemeClr) to #RRGGBB.
     *
     * @param \DOMElement $clr The a:srgbClr or a:schemeClr element.
     * @return string|null The #RRGGBB colour, or null if unresolved.
     */
    private function colour_of(\DOMElement $clr): ?string {
        if ($clr->localName === 'srgbClr') {
            $val = $clr->getAttribute('val');
            return preg_match('/^[0-9A-Fa-f]{6}$/', $val) ? '#' . strtoupper($val) : null;
        }
        if ($clr->localName === 'schemeClr') {
            return $this->theme()->colour($clr->getAttribute('val'));
        }
        return null;
    }

    /**
     * Flattens parsed paragraphs to plain, newline-separated label text, marking
     * bulleted lines with a leading bullet so a diagram box keeps its structure.
     *
     * @param array[] $paras Entries of ['text'=>string, 'level'=>int, 'nobullet'=>bool].
     * @return string The plain-text label.
     */
    private function plain_lines(array $paras): string {
        $lines = [];
        $multiple = count($paras) > 1;
        foreach ($paras as $p) {
            $text = html_entity_decode(strip_tags(str_replace("\n", ' ', $p['text'])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim($text);
            if ($text === '') {
                continue;
            }
            if ($multiple && empty($p['nobullet']) && (int) ($p['level'] ?? 0) >= 0 && count($paras) > 1) {
                $indent = str_repeat('   ', max(0, (int) ($p['level'] ?? 0)));
                $lines[] = $indent . '• ' . $text;
            } else {
                $lines[] = $text;
            }
        }
        return implode("\n", $lines);
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
     * Reads a shape's extent (width, height) in EMU, scaled by any group transform.
     *
     * @param \DOMElement $el The shape element.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @param array|null $tf Coordinate transform inherited from enclosing groups.
     * @return array A [height, width] pair in EMU, or [0, 0] when no extent is present.
     */
    private function extent(\DOMElement $el, \DOMXPath $xpath, ?array $tf = null): array {
        // Anchor on the transform's own extent (the a:ext beside a:off in the
        // shape's xfrm), not the first descendant a:ext: a blip extension list
        // — e.g. an SVG-backed image — also carries an a:ext, without cx/cy.
        $off = $xpath->query('.//a:off', $el)->item(0);
        $ext = $off instanceof \DOMElement ? $xpath->query('parent::*/a:ext', $off)->item(0) : null;
        if ($ext instanceof \DOMElement && $ext->getAttribute('cx') !== '') {
            $cx = (int) $ext->getAttribute('cx');
            $cy = (int) $ext->getAttribute('cy');
            if ($tf !== null) {
                $cx = (int) round($cx * $tf['sx']);
                $cy = (int) round($cy * $tf['sy']);
            }
            return [$cy, $cx];
        }
        return [0, 0];
    }

    /**
     * Returns a shape's on-slide bounding box as [y, x, cy, cx] in EMU.
     *
     * The stored a:off/a:ext describe the shape's unrotated box. When the shape
     * carries an a:xfrm rotation, that box no longer matches what the reader
     * sees: a text box turned a quarter turn is tall and narrow on screen while
     * its extents still read wide and short. Row-overlap and column grouping key
     * off the on-slide footprint, so rotate the box about its centre (rotation is
     * centre-anchored in DrawingML) and return the axis-aligned bounds — swapping
     * the extents for a quarter turn and shifting the origin to keep the centre
     * fixed. Group scaling is assumed roughly uniform, so it is applied before the
     * rotation via offset()/extent().
     *
     * @param \DOMElement $el The shape element.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @param array|null $tf Coordinate transform inherited from enclosing groups.
     * @return array The [y, x, cy, cx] bounds; cy/cx are 0 when no extent is present.
     */
    private function geometry(\DOMElement $el, \DOMXPath $xpath, ?array $tf = null): array {
        [$y, $x] = $this->offset($el, $xpath, $tf);
        [$cy, $cx] = $this->extent($el, $xpath, $tf);
        if ($cx <= 0 || $cy <= 0 || $x === self::NO_OFFSET) {
            return [$y, $x, $cy, $cx];
        }
        $off = $xpath->query('.//a:off', $el)->item(0);
        $xfrm = $off instanceof \DOMElement ? $xpath->query('parent::*', $off)->item(0) : null;
        $rot = $xfrm instanceof \DOMElement ? (int) $xfrm->getAttribute('rot') : 0;
        if ($rot === 0) {
            return [$y, $x, $cy, $cx];
        }
        // Rotation is in 60000ths of a degree. Feed it to deg2rad as a float — the
        // footprint below uses abs(cos)/abs(sin), so the angle's sign and any
        // whole-turn wrap do not matter and no normalisation is needed.
        $rad = deg2rad($rot / 60000.0);
        $cos = abs(cos($rad));
        $sin = abs(sin($rad));
        $w = (int) round($cx * $cos + $cy * $sin);
        $h = (int) round($cx * $sin + $cy * $cos);
        $centrex = $x + $cx / 2;
        $centrey = $y + $cy / 2;
        return [(int) round($centrey - $h / 2), (int) round($centrex - $w / 2), $h, $w];
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
     * @param string $category Placeholder category ("body"|"other") for master size lookup.
     * @return array[] Entries of ['text'=>string, 'level'=>int, 'nobullet'=>bool].
     */
    private function paragraphs(\DOMElement $sp, \DOMXPath $xpath, string $category = 'other'): array {
        $out = [];
        $liststyle = $this->list_style_bullets($sp, $xpath);
        $listsizes = $this->list_style_sizes($sp, $xpath);
        $layoutsizes = $this->layout_sizes($sp, $xpath);
        $mastersizes = $this->master_text_styles()[$category] ?? [];
        // PowerPoint may shrink overflowing text with a body autofit scale; apply
        // it so the imported size matches what the slide actually showed.
        $autofit = $this->autofit_scale($sp, $xpath);
        foreach ($xpath->query('.//a:p', $sp) as $p) {
            $ppr = $xpath->query('a:pPr', $p)->item(0);
            $level = 0;
            $nobullet = null;
            $paradefault = 0;
            if ($ppr instanceof \DOMElement) {
                // DrawingML outlines allow levels 0-8; clamp so a crafted lvl (the
                // uploaded XML is not schema-validated) cannot drive deep nesting.
                $level = min(self::MAX_LIST_LEVEL, max(0, (int) $ppr->getAttribute('lvl')));
                $nobullet = self::bullet_state($ppr, $xpath);
                $pdef = $xpath->query('a:defRPr', $ppr)->item(0);
                if ($pdef instanceof \DOMElement && $pdef->getAttribute('sz') !== '') {
                    $paradefault = (int) $pdef->getAttribute('sz');
                }
            }
            // Size for a run that carries none of its own, most specific first:
            // the paragraph default, then the shape's list style, then the layout
            // placeholder's style, then the slide master's style for this level.
            $fallback = $paradefault
                ?: ($listsizes[$level] ?? ($layoutsizes[$level] ?? ($mastersizes[$level] ?? 0)));
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
                    // Preserve the run's on-slide size so body text that is large
                    // beside an image is not flattened to the reader default. An
                    // explicit run size wins; otherwise inherit the paragraph,
                    // shape or master default resolved above.
                    $runsize = $rpr instanceof \DOMElement && $rpr->getAttribute('sz') !== ''
                        ? (int) $rpr->getAttribute('sz') : $fallback;
                    if ($runsize > 0 && $autofit !== 1.0) {
                        $runsize = (int) round($runsize * $autofit);
                    }
                    if ($runsize > 0) {
                        $text = '<span style="font-size:' . self::points($runsize) . 'pt;">' . $text . '</span>';
                    }
                    $buf .= $text;
                }
            }
            $line = trim($buf);
            if ($line === '') {
                continue;
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
     * Classifies a text shape for master-style size lookup.
     *
     * The title placeholder is handled before this runs, so a placeholder here is
     * a body-family one (body, subTitle, object) and maps to the master's body
     * style; a shape with no placeholder is a free text box and maps to "other".
     *
     * @param \DOMElement $sp The shape element.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @return string "body" or "other".
     */
    private function text_category(\DOMElement $sp, \DOMXPath $xpath): string {
        $ph = $xpath->query('./p:nvSpPr/p:nvPr/p:ph', $sp)->item(0);
        return $ph instanceof \DOMElement ? 'body' : 'other';
    }

    /**
     * Per-level default font sizes declared by a shape's own txBody list style.
     *
     * @param \DOMElement $sp The shape element.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @return array<int,int> Outline level (0-based) => size in 1/100 pt.
     */
    private function list_style_sizes(\DOMElement $sp, \DOMXPath $xpath): array {
        $sizes = [];
        $lststyle = $xpath->query('./p:txBody/a:lstStyle', $sp)->item(0);
        if (!$lststyle instanceof \DOMElement) {
            return $sizes;
        }
        foreach ($lststyle->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->namespaceURI !== package::NS_A) {
                continue;
            }
            if (!preg_match('/^lvl([1-9])pPr$/', $child->localName, $m)) {
                continue;
            }
            $def = $xpath->query('a:defRPr', $child)->item(0);
            $sz = $def instanceof \DOMElement ? (int) $def->getAttribute('sz') : 0;
            if ($sz > 0) {
                $sizes[(int) $m[1] - 1] = $sz;
            }
        }
        return $sizes;
    }

    /**
     * Namespaced XPath over this slide's layout part, loaded once and cached.
     *
     * @return \DOMXPath|null The layout XPath, or null when no layout is reachable.
     */
    private function layout_xpath(): ?\DOMXPath {
        if (!$this->layouttried) {
            $this->layouttried = true;
            $path = $this->rel_match($this->path, '#slideLayout\d+\.xml$#');
            $doc = $path === null ? null : $this->package->get_xml($path);
            $this->layoutxpath = $doc instanceof \DOMDocument ? package::xpath($doc) : null;
        }
        return $this->layoutxpath;
    }

    /**
     * Per-level default font sizes from the shape's matching layout placeholder.
     *
     * A slide placeholder inherits typography from the placeholder with the same
     * type and index in its layout, which sits between the shape's own list style
     * and the master's generic txStyles in the inheritance chain.
     *
     * @param \DOMElement $sp The slide shape element.
     * @param \DOMXPath $xpath Namespaced XPath over the slide.
     * @return array<int,int> Outline level (0-based) => size in 1/100 pt.
     */
    private function layout_sizes(\DOMElement $sp, \DOMXPath $xpath): array {
        $ph = $xpath->query('./p:nvSpPr/p:nvPr/p:ph', $sp)->item(0);
        $lx = $this->layout_xpath();
        if (!$ph instanceof \DOMElement || $lx === null) {
            return [];
        }
        $type = $ph->getAttribute('type');
        $idx = $ph->getAttribute('idx');
        foreach ($lx->query('//p:sp') as $lsp) {
            $lph = $lx->query('./p:nvSpPr/p:nvPr/p:ph', $lsp)->item(0);
            if (
                $lph instanceof \DOMElement
                    && $lph->getAttribute('type') === $type
                    && $lph->getAttribute('idx') === $idx
            ) {
                return $this->list_style_sizes($lsp, $lx);
            }
        }
        return [];
    }

    /**
     * The autofit font scale PowerPoint applied to shrink overflowing body text.
     *
     * @param \DOMElement $sp The shape element.
     * @param \DOMXPath $xpath Namespaced XPath.
     * @return float The scale factor (1.0 when the shape does not shrink its text).
     */
    private function autofit_scale(\DOMElement $sp, \DOMXPath $xpath): float {
        $fit = $xpath->query('./p:txBody/a:bodyPr/a:normAutofit', $sp)->item(0);
        if (!$fit instanceof \DOMElement || $fit->getAttribute('fontScale') === '') {
            return 1.0;
        }
        // The fontScale is a percentage in 1/1000 of a percent (100000 means 100%).
        $scale = (int) $fit->getAttribute('fontScale');
        return $scale > 0 ? $scale / 100000 : 1.0;
    }

    /**
     * Master default font sizes by placeholder category and outline level.
     *
     * PowerPoint templates keep the default point size for each placeholder kind
     * and indent level in the slide master's txStyles, which is where a run's size
     * comes from when the run, its paragraph and its shape all leave it unset.
     * Traced once per slide and cached; an empty map when no master is reachable.
     *
     * @return array<string,array<int,int>> Category (title|body|other) => level => size in 1/100 pt.
     */
    private function master_text_styles(): array {
        if ($this->textstyles !== null) {
            return $this->textstyles;
        }
        $styles = ['title' => [], 'body' => [], 'other' => []];
        $master = $this->trace_master();
        $doc = $master === null ? null : $this->package->get_xml($master);
        if ($doc instanceof \DOMDocument) {
            $mx = package::xpath($doc);
            foreach (['titleStyle' => 'title', 'bodyStyle' => 'body', 'otherStyle' => 'other'] as $node => $cat) {
                $style = $mx->query('/p:sldMaster/p:txStyles/p:' . $node)->item(0);
                if (!$style instanceof \DOMElement) {
                    continue;
                }
                foreach ($style->childNodes as $lvl) {
                    if (!$lvl instanceof \DOMElement || $lvl->namespaceURI !== package::NS_A) {
                        continue;
                    }
                    if (!preg_match('/^lvl([1-9])pPr$/', $lvl->localName, $m)) {
                        continue;
                    }
                    $def = $mx->query('a:defRPr', $lvl)->item(0);
                    $sz = $def instanceof \DOMElement ? (int) $def->getAttribute('sz') : 0;
                    if ($sz > 0) {
                        $styles[$cat][(int) $m[1] - 1] = $sz;
                    }
                }
            }
        }
        $this->textstyles = $styles;
        return $this->textstyles;
    }

    /**
     * Follows the slide -> layout -> master relationship chain to the master part.
     *
     * @return string|null The master's zip path, or null when the chain breaks.
     */
    private function trace_master(): ?string {
        $layout = $this->rel_match($this->path, '#slideLayout\d+\.xml$#');
        if ($layout === null) {
            return null;
        }
        return $this->rel_match($layout, '#slideMaster\d+\.xml$#');
    }

    /**
     * First relationship target of a part whose resolved path matches a pattern.
     *
     * @param string $partpath Zip path whose rels are searched.
     * @param string $pattern Regex the target must match.
     * @return string|null The matching target path, or null when none matches.
     */
    private function rel_match(string $partpath, string $pattern): ?string {
        foreach ($this->package->get_rels($partpath) as $target) {
            if (preg_match($pattern, $target)) {
                return $target;
            }
        }
        return null;
    }

    /**
     * A width in EMU as a percent of the slide width.
     *
     * @param int $cx Width in EMU.
     * @return int Percent in 1-100, or 0 when the width or slide width is unknown.
     */
    private function width_percent(int $cx): int {
        $slidewidth = $this->package->slide_width();
        if ($cx <= 0 || $slidewidth <= 0) {
            return 0;
        }
        return min(100, max(1, (int) round($cx / $slidewidth * 100)));
    }

    /**
     * Formats a DrawingML font size (1/100 pt) as a trimmed point value.
     *
     * @param int $sz Size in hundredths of a point.
     * @return string The size in points, e.g. "28" or "13.5".
     */
    private static function points(int $sz): string {
        return rtrim(rtrim(number_format($sz / 100, 2, '.', ''), '0'), '.');
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
