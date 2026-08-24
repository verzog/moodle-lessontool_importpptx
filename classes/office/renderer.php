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

    /**
     * @var string[] Font families a render may be forced to use. Restricting the
     * choice keeps an arbitrary (and possibly unsafe) name out of the theme XML,
     * and each is a widely packaged, metric-compatible face that keeps text close
     * to its intended size: Carlito matches Calibri and the newer Aptos, the
     * Liberation set matches Arial/Times, and Caladea matches Cambria (Office's
     * serif). (A metric-incompatible face such as DejaVu Sans is deliberately not
     * offered — forcing it on a deck sized for a narrower font makes the text over-
     * or undersized.)
     */
    const RENDER_FONTS = ['Carlito', 'Liberation Sans', 'Liberation Serif', 'Caladea'];

    /** @var float Line advance as a multiple of the font point size, for fit estimates. */
    const FIT_LINE_HEIGHT = 1.2;

    /** @var float Average glyph advance as a fraction of the font point size. */
    const FIT_CHAR_WIDTH = 0.52;

    /** @var float Fraction of the box a shrunk body is allowed to fill (leaves a margin). */
    const FIT_TARGET_FILL = 0.93;

    /** @var float Smallest font scale the shrink-to-fit pass will apply. */
    const FIT_MIN_SCALE = 0.30;

    /** @var int Default body point size (x100) when a paragraph declares none. */
    const FIT_DEFAULT_SZ = 1800;

    /** @var int Assumed rasterisation DPI when measuring glyph widths with GD. */
    const FIT_DPI = 96;

    /**
     * @var string[] Font files probed, in order, to measure text width. The list
     * mirrors {@see self::RENDER_FONTS}: metric-compatible faces LibreOffice also
     * substitutes in, so a measurement here matches what it will render.
     */
    const FIT_FONT_CANDIDATES = [
        '/usr/share/fonts/truetype/crosextra/Carlito-Regular.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    ];

    /** @var array Render-font name (string) to the TTF path (string) used to measure it. */
    const FIT_FONT_FILES = [
        'Carlito' => '/usr/share/fonts/truetype/crosextra/Carlito-Regular.ttf',
        'Liberation Sans' => '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        'Liberation Serif' => '/usr/share/fonts/truetype/liberation/LiberationSerif-Regular.ttf',
        'Caladea' => '/usr/share/fonts/truetype/crosextra/Caladea-Regular.ttf',
    ];

    /**
     * @var string[] Preset geometries whose text rectangle is the full extent, so
     * the fit estimate is valid. Non-rectangular presets (ellipse, arrows, …) lay
     * text out in a smaller internal rectangle and are left alone.
     */
    const FIT_RECT_PRESETS = [
        'rect', 'roundRect', 'round1Rect', 'round2SameRect', 'round2DiagRect',
        'snip1Rect', 'snip2SameRect', 'snip2DiagRect', 'snipRoundRect', 'plaque',
    ];

    /** @var float Fallback width, in em, for a full-width (e.g. CJK) glyph. */
    const FIT_FULLWIDTH_EM = 1.0;

    /** @var bool|null Cached availability result for this request. */
    private static ?bool $available = null;

    /** @var bool|null Cached LibreOffice-only availability for this request. */
    private static ?bool $sofficeavailable = null;

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
     * Whether the LibreOffice binary alone can be executed.
     *
     * Split out from {@see self::is_available()} (which also requires poppler) so
     * the import form can tell the two backends apart and report which binary is
     * missing. Cached per host and per request on the same terms as the combined
     * probe; no lock is taken, as a single-binary form-load probe is cheap enough
     * that serialising it is not worth the coordination.
     *
     * @return bool True if the soffice binary can be run.
     */
    public static function libreoffice_available(): bool {
        if (self::$sofficeavailable !== null) {
            return self::$sofficeavailable;
        }
        $cached = get_config('local_lessonimportpptx', self::cache_key('sofficeavailable'));
        $checked = (int) get_config('local_lessonimportpptx', self::cache_key('sofficeavailablecheck'));
        if ($cached !== false && (time() - $checked) < self::AVAILABLE_TTL) {
            return self::$sofficeavailable = (bool) (int) $cached;
        }
        self::$sofficeavailable = self::can_run_soffice();
        set_config(self::cache_key('sofficeavailable'), self::$sofficeavailable ? 1 : 0, 'local_lessonimportpptx');
        set_config(self::cache_key('sofficeavailablecheck'), time(), 'local_lessonimportpptx');
        return self::$sofficeavailable;
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
     * @param string $renderfont A font family (from {@see self::RENDER_FONTS}) to
     *                           force on the deck before rendering, or '' to keep
     *                           the deck's own fonts.
     * @return \Generator Yields [slidenumber, filename, bytes] arrays.
     * @throws \moodle_exception If conversion or rendering fails.
     */
    public function render_pages(\stored_file $pptx, int $maxdim, string $renderfont = ''): \Generator {
        $dir = make_request_directory();
        $source = $dir . '/import.pptx';
        $pptx->copy_content_to($source);
        self::assert_archive_within_limits($source);
        self::apply_render_font($source, $renderfont);
        self::apply_autofit_shrink($source, $renderfont);
        // Re-check after the rewrites: a longer font name or added attributes can
        // grow the XML parts, so the size caps must hold for the archive actually
        // handed to LibreOffice.
        self::assert_archive_within_limits($source);

        $pdfpath = self::convert_to_pdf($source, $dir);
        if ($pdfpath === null) {
            throw new \moodle_exception('errorofficerender', 'local_lessonimportpptx');
        }
        yield from (new pdfrenderer())->render_path($pdfpath, $maxdim);
    }

    /**
     * Forces a single Latin font on the staged .pptx before it is rendered.
     *
     * A deck rendered on a server that lacks its fonts (for example a deck in
     * Aptos, Office's 2024 default) has them substituted by LibreOffice, often
     * with a wider face that overflows the deck's fixed-size text boxes. Rewriting
     * every Latin typeface — the theme's major/minor fonts and any run-level
     * override — to one installed, metric-friendly family sidesteps that. Only
     * this temporary render copy is touched; the imported editable content and the
     * original upload are untouched.
     *
     * @param string $source Absolute path to the staged .pptx (modified in place).
     * @param string $renderfont The font family to force, or '' / an unknown name
     *                           to leave the deck's fonts alone.
     * @return void
     */
    private static function apply_render_font(string $source, string $renderfont): void {
        if ($renderfont === '' || !in_array($renderfont, self::RENDER_FONTS, true)) {
            return;
        }
        $zip = new \ZipArchive();
        if ($zip->open($source) !== true) {
            return;
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            // Text fonts appear across the presentation's DrawingML parts — the
            // theme scheme, slides, layouts and masters, and also SmartArt
            // diagrams and charts — so rewrite every ppt/*.xml part; the
            // has-a-latin check below skips the ones that carry no font.
            if (!preg_match('#^ppt/.*\.xml$#', (string) $name)) {
                continue;
            }
            $xml = $zip->getFromIndex($i);
            if ($xml === false || stripos($xml, 'latin') === false) {
                continue;
            }
            // The DrawingML "latin" element is almost always the a: prefix, but the
            // prefix is only bound by declaration, so match any (or none).
            $rewritten = preg_replace(
                '/(<(?:[a-zA-Z_][\w.\-]*:)?latin\b[^>]*\btypeface=")[^"]*(")/',
                '${1}' . $renderfont . '${2}',
                $xml
            );
            if (is_string($rewritten) && $rewritten !== $xml) {
                $zip->addFromString($name, $rewritten);
            }
        }
        $zip->close();
    }

    /**
     * Bakes a shrink-to-fit scale into text bodies that ask for one.
     *
     * PowerPoint's "Shrink text on overflow" (a bare {@code <a:normAutofit/>})
     * computes its scale live when the slide is shown and does not persist it in
     * the file. LibreOffice does not recompute that scale during a headless PDF
     * conversion, so it draws the text full size and it spills out of the box in
     * the rendered image. Estimating the overflow here and writing an explicit
     * {@code fontScale} — which LibreOffice does honour — reproduces the shrink.
     *
     * Only bodies that already opt into shrink-to-fit are touched, and only ever
     * to make text smaller, so a body that already fits is left unchanged. Only
     * the temporary render copy is modified.
     *
     * @param string $source Absolute path to the staged .pptx (modified in place).
     * @param string $renderfont The render font forced on the deck, or '' — used to
     *                           measure text in the family LibreOffice will render.
     * @return void
     */
    private static function apply_autofit_shrink(string $source, string $renderfont = ''): void {
        $zip = new \ZipArchive();
        if ($zip->open($source) !== true) {
            return;
        }
        $fontpath = self::fit_font_path($renderfont);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            // Only rendered slide bodies carry the explicit geometry needed to
            // size the fit; layout/master placeholders inherit theirs.
            if (!preg_match('#^ppt/slides/slide\d+\.xml$#', $name)) {
                continue;
            }
            $xml = $zip->getFromIndex($i);
            if ($xml === false || strpos($xml, 'normAutofit') === false) {
                continue;
            }
            // A placeholder's real font size can live in its layout or master, so
            // resolve that inheritance context before rescaling this slide's text.
            $styles = self::build_style_context($zip, $name);
            $rewritten = self::shrink_slide_autofit($xml, $fontpath, $styles);
            if ($rewritten !== null && $rewritten !== $xml) {
                $zip->addFromString($name, $rewritten);
            }
        }
        $zip->close();
    }

    /**
     * Shrinks each overflowing autofit body by reducing its real font sizes.
     *
     * PowerPoint's "Shrink text on overflow" scale lives in {@code <a:normAutofit
     * fontScale="…"/>}, but LibreOffice does not apply that attribute during a
     * headless PDF conversion, so the text renders full size and spills out of the
     * box. Rather than (re)writing the ignored attribute, this applies the scale
     * to the actual run sizes — which LibreOffice always honours — using
     * PowerPoint's own fontScale where it baked one, or an estimate for a bare
     * body. The attribute is then removed so a future LibreOffice that does honour
     * it cannot shrink a second time.
     *
     * @param string $xml The slide part's XML.
     * @param string $fontpath A TTF used to measure text, or '' to estimate widths.
     * @param array $styles Layout/master size inheritance context for the slide.
     * @return string|null The rewritten XML, or null if it could not be parsed.
     */
    private static function shrink_slide_autofit(string $xml, string $fontpath = '', array $styles = []): ?string {
        $doc = new \DOMDocument();
        $ok = @$doc->loadXML($xml);
        if ($ok === false) {
            return null;
        }
        $a = 'http://schemas.openxmlformats.org/drawingml/2006/main';
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('a', $a);
        $xpath->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $changed = false;
        foreach ($xpath->query('//a:normAutofit') as $naf) {
            $bodypr = $naf->parentNode;
            $txbody = $bodypr instanceof \DOMElement ? $bodypr->parentNode : null;
            if (!$txbody instanceof \DOMElement) {
                continue;
            }
            if ($naf->hasAttribute('fontScale')) {
                // Honour the shrink PowerPoint already computed and stored.
                $scale = ((int) $naf->getAttribute('fontScale')) / 100000.0;
            } else {
                // No stored scale: estimate one from the box geometry.
                $scale = self::bare_autofit_scale($xpath, $naf, $bodypr, $txbody, $fontpath);
            }
            if ($scale <= 0.0 || $scale >= 1.0) {
                continue;
            }
            // A placeholder's inherited size lives in its layout/master, keyed by
            // the shape's placeholder type and index.
            [$phtype, $phidx] = self::placeholder_key($xpath, $txbody);
            self::scale_body_sizes($xpath, $txbody, $scale, $phtype, $phidx, $styles);
            // LibreOffice ignores lnSpcReduction too, so fold it into real line
            // spacing before the hint is dropped.
            if ($naf->hasAttribute('lnSpcReduction')) {
                $reduction = ((int) $naf->getAttribute('lnSpcReduction')) / 100000.0;
                if ($reduction > 0.0) {
                    self::bake_line_spacing($xpath, $txbody, $reduction);
                }
            }
            // The scale is now baked into the sizes, so drop the (ignored) hints to
            // avoid any double shrink if LibreOffice later learns to apply them.
            $naf->removeAttribute('fontScale');
            $naf->removeAttribute('lnSpcReduction');
            $changed = true;
        }
        if (!$changed) {
            return $xml;
        }
        $out = $doc->saveXML();
        return $out === false ? null : $out;
    }

    /**
     * Returns a text body's placeholder [type, idx] as strings ('' when absent).
     *
     * @param \DOMXPath $xpath A path bound to the slide's namespaces.
     * @param \DOMNode $txbody The p:txBody element.
     * @return array The placeholder [type, idx], each a string ('' when absent).
     */
    private static function placeholder_key(\DOMXPath $xpath, \DOMNode $txbody): array {
        $shape = $txbody->parentNode;
        if (!$shape instanceof \DOMElement) {
            return ['', ''];
        }
        $ph = $xpath->query('.//p:nvSpPr/p:nvPr/p:ph', $shape)->item(0);
        if (!$ph instanceof \DOMElement) {
            return ['', ''];
        }
        return [$ph->getAttribute('type'), $ph->getAttribute('idx')];
    }

    /**
     * Estimates the shrink scale for a bare autofit body (no stored fontScale).
     *
     * Requires an explicit rectangle-like box to size against; returns 1.0 (no
     * shrink) when the shape's geometry is missing, non-rectangular or vertical.
     *
     * @param \DOMXPath $xpath A path bound to the slide's namespaces.
     * @param \DOMNode $naf The a:normAutofit element.
     * @param \DOMNode $bodypr The a:bodyPr element.
     * @param \DOMNode $txbody The p:txBody element.
     * @param string $fontpath A TTF used to measure text, or '' to estimate widths.
     * @return float A scale in (0, 1]; 1.0 means no shrink.
     */
    private static function bare_autofit_scale(
        \DOMXPath $xpath,
        \DOMNode $naf,
        \DOMNode $bodypr,
        \DOMNode $txbody,
        string $fontpath
    ): float {
        $shape = $txbody->parentNode;
        if (!$shape instanceof \DOMElement) {
            return 1.0;
        }
        $ext = $xpath->query('.//a:xfrm/a:ext', $shape)->item(0);
        if (!$ext instanceof \DOMElement) {
            return 1.0;
        }
        // Only rectangle-like presets lay text out across the full extent; a
        // non-rectangular preset (ellipse, arrow, …) uses a smaller internal
        // rectangle the estimate would misjudge, so leave those unchanged.
        $prst = $xpath->query('.//a:prstGeom/@prst', $shape)->item(0);
        if ($prst !== null && !in_array($prst->nodeValue, self::FIT_RECT_PRESETS, true)) {
            return 1.0;
        }
        $cx = (int) $ext->getAttribute('cx');
        $cy = (int) $ext->getAttribute('cy');
        if ($cx <= 0 || $cy <= 0) {
            return 1.0;
        }
        return self::estimate_fit_scale($xpath, $bodypr, $txbody, $cx, $cy, $fontpath);
    }

    /**
     * Multiplies every font size in a text body by a scale, in place.
     *
     * Explicit sizes (runs, fields, paragraph defaults, end-of-paragraph marks and
     * the body list style) are scaled directly; a run that inherits its size gets
     * an explicit, scaled size injected — but only when that inherited size can be
     * resolved (slide, layout, master or presentation), so a truly unknown size is
     * left as-is rather than replaced with a wrong guess. Sizes are in hundredths
     * of a point and never dropped below 1pt.
     *
     * @param \DOMXPath $xpath A path bound to the slide's namespaces.
     * @param \DOMNode $txbody The p:txBody element to rescale.
     * @param float $scale The scale to apply (0 < scale < 1).
     * @param string $phtype The shape's placeholder type ('' if none).
     * @param string $phidx The shape's placeholder index ('' if none).
     * @param array $styles Layout/master size inheritance context for the slide.
     * @return void
     */
    private static function scale_body_sizes(
        \DOMXPath $xpath,
        \DOMNode $txbody,
        float $scale,
        string $phtype = '',
        string $phidx = '',
        array $styles = []
    ): void {
        $a = 'http://schemas.openxmlformats.org/drawingml/2006/main';
        foreach ($xpath->query('a:p', $txbody) as $para) {
            $ppr = $xpath->query('a:pPr', $para)->item(0);
            $inherited = self::inherited_size_x100($xpath, $para, $txbody, $ppr, $phtype, $phidx, $styles);
            // Runs and fields (a:fld, e.g. a date or slide number) both carry sizes.
            foreach ($xpath->query('a:r | a:fld', $para) as $run) {
                $rpr = $xpath->query('a:rPr', $run)->item(0);
                if ($rpr instanceof \DOMElement && $rpr->hasAttribute('sz')) {
                    $orig = (int) $rpr->getAttribute('sz');
                } else if ($inherited > 0) {
                    $orig = $inherited;
                    if (!$rpr instanceof \DOMElement) {
                        $rpr = $run->ownerDocument->createElementNS($a, 'a:rPr');
                        $run->insertBefore($rpr, $run->firstChild);
                    }
                } else {
                    // Inherited size unknown: leave it rather than guess wrong.
                    continue;
                }
                $rpr->setAttribute('sz', (string) max(100, (int) round($orig * $scale)));
            }
        }
        // Paragraph defaults, end-of-paragraph marks and list-style sizes are not
        // run sizes handled above, so scale whatever explicit sizes they declare.
        foreach ($xpath->query('.//a:defRPr/@sz | .//a:endParaRPr/@sz', $txbody) as $szattr) {
            $szattr->nodeValue = (string) max(100, (int) round(((int) $szattr->nodeValue) * $scale));
        }
    }

    /**
     * The size a paragraph's runs inherit when they declare none, in 1/100 pt.
     *
     * Walks the DrawingML inheritance chain: the paragraph's own default and
     * end-of-paragraph mark, the body list style, then — for a placeholder — the
     * slide layout (by index, then type), the master text styles and finally the
     * presentation default. Returns 0 when no size can be determined, so the caller
     * can leave such a run untouched.
     *
     * @param \DOMXPath $xpath A path bound to the slide's namespaces.
     * @param \DOMNode $para The a:p element.
     * @param \DOMNode $txbody The p:txBody element (for its a:lstStyle).
     * @param \DOMElement|null $ppr The paragraph's a:pPr, or null.
     * @param string $phtype The shape's placeholder type ('' if none).
     * @param string $phidx The shape's placeholder index ('' if none).
     * @param array $styles Layout/master size inheritance context.
     * @return int The inherited size in hundredths of a point, or 0 if unknown.
     */
    private static function inherited_size_x100(
        \DOMXPath $xpath,
        \DOMNode $para,
        \DOMNode $txbody,
        ?\DOMElement $ppr,
        string $phtype,
        string $phidx,
        array $styles
    ): int {
        $defrpr = $xpath->query('a:pPr/a:defRPr/@sz', $para)->item(0);
        if ($defrpr !== null) {
            return (int) $defrpr->nodeValue;
        }
        $endpara = $xpath->query('a:endParaRPr/@sz', $para)->item(0);
        if ($endpara !== null) {
            return (int) $endpara->nodeValue;
        }
        $level = 0;
        if ($ppr instanceof \DOMElement && $ppr->hasAttribute('lvl')) {
            $level = (int) $ppr->getAttribute('lvl');
        }
        $lvl = $xpath->query('a:lstStyle/a:lvl' . ($level + 1) . 'pPr/a:defRPr/@sz', $txbody)->item(0);
        if ($lvl !== null) {
            return (int) $lvl->nodeValue;
        }
        // Placeholder inheritance: layout (by idx then type), master, presentation.
        if ($phidx !== '' && isset($styles['layout_idx'][$phidx][$level])) {
            return $styles['layout_idx'][$phidx][$level];
        }
        if (isset($styles['layout_type'][$phtype][$level])) {
            return $styles['layout_type'][$phtype][$level];
        }
        $group = self::master_style_group($phtype);
        if (isset($styles['master'][$group][$level])) {
            return $styles['master'][$group][$level];
        }
        if (isset($styles['presentation'][$level])) {
            return $styles['presentation'][$level];
        }
        return 0;
    }

    /**
     * Maps a placeholder type to the master text-style group that sizes it.
     *
     * @param string $phtype The placeholder type ('' when none, i.e. a body).
     * @return string One of 'title', 'body' or 'other'.
     */
    private static function master_style_group(string $phtype): string {
        if ($phtype === 'title' || $phtype === 'ctrTitle') {
            return 'title';
        }
        if ($phtype === '' || in_array($phtype, ['body', 'subTitle', 'obj'], true)) {
            return 'body';
        }
        return 'other';
    }

    /**
     * Bakes a line-spacing reduction into a body's paragraphs, in place.
     *
     * LibreOffice ignores normAutofit's lnSpcReduction, so the compression it asks
     * for is applied to each paragraph's a:lnSpc — scaling an existing spacing or
     * injecting a percentage one when none is declared.
     *
     * @param \DOMXPath $xpath A path bound to the slide's namespaces.
     * @param \DOMNode $txbody The p:txBody element.
     * @param float $reduction The fraction to compress line spacing by (0 < r < 1).
     * @return void
     */
    private static function bake_line_spacing(\DOMXPath $xpath, \DOMNode $txbody, float $reduction): void {
        $a = 'http://schemas.openxmlformats.org/drawingml/2006/main';
        $factor = 1.0 - $reduction;
        if ($factor <= 0.0) {
            return;
        }
        foreach ($xpath->query('a:p', $txbody) as $para) {
            $ppr = $xpath->query('a:pPr', $para)->item(0);
            if (!$ppr instanceof \DOMElement) {
                $ppr = $para->ownerDocument->createElementNS($a, 'a:pPr');
                $para->insertBefore($ppr, $para->firstChild);
            }
            $lnspc = $xpath->query('a:lnSpc', $ppr)->item(0);
            if ($lnspc instanceof \DOMElement) {
                foreach ($xpath->query('a:spcPct/@val | a:spcPts/@val', $lnspc) as $val) {
                    $val->nodeValue = (string) max(1, (int) round(((int) $val->nodeValue) * $factor));
                }
                continue;
            }
            // No explicit spacing: single spacing compressed by the reduction.
            $lnspc = $para->ownerDocument->createElementNS($a, 'a:lnSpc');
            $pct = $para->ownerDocument->createElementNS($a, 'a:spcPct');
            $pct->setAttribute('val', (string) max(1, (int) round(100000 * $factor)));
            $lnspc->appendChild($pct);
            $ppr->insertBefore($lnspc, $ppr->firstChild);
        }
    }

    /**
     * Builds the layout/master/presentation size-inheritance context for a slide.
     *
     * @param \ZipArchive $zip The open presentation archive.
     * @param string $slidename The slide part name (ppt/slides/slideN.xml).
     * @return array Keyed by 'layout_idx', 'layout_type', 'master', 'presentation'.
     */
    private static function build_style_context(\ZipArchive $zip, string $slidename): array {
        $styles = ['layout_idx' => [], 'layout_type' => [], 'master' => [], 'presentation' => []];
        $layoutname = self::related_part($zip, $slidename, 'slideLayout');
        if ($layoutname !== '') {
            [$styles['layout_idx'], $styles['layout_type']] = self::parse_layout_sizes($zip->getFromName($layoutname));
            $mastername = self::related_part($zip, $layoutname, 'slideMaster');
            if ($mastername !== '') {
                $styles['master'] = self::parse_master_sizes($zip->getFromName($mastername));
            }
        }
        $styles['presentation'] = self::parse_default_text_style($zip->getFromName('ppt/presentation.xml'));
        return $styles;
    }

    /**
     * Resolves a part's related layout/master target from its .rels, normalised.
     *
     * @param \ZipArchive $zip The open presentation archive.
     * @param string $part The part whose relationships to read.
     * @param string $kind The relationship target stem ('slideLayout'/'slideMaster').
     * @return string The related part name, or '' when not found.
     */
    private static function related_part(\ZipArchive $zip, string $part, string $kind): string {
        $rels = $zip->getFromName(dirname($part) . '/_rels/' . basename($part) . '.rels');
        if ($rels === false || !preg_match('#(?:\.\./)?' . $kind . 's/' . $kind . '\d+\.xml#', $rels, $m)) {
            return '';
        }
        return 'ppt/' . $kind . 's/' . basename($m[0]);
    }

    /**
     * Parses a slide layout's placeholder sizes into by-index and by-type maps.
     *
     * @param string|false $xml The layout XML, or false.
     * @return array A [by-index, by-type] pair; each maps a key to level => sz.
     */
    private static function parse_layout_sizes($xml): array {
        $byidx = [];
        $bytype = [];
        $doc = new \DOMDocument();
        if ($xml === false || @$doc->loadXML($xml) === false) {
            return [$byidx, $bytype];
        }
        $a = 'http://schemas.openxmlformats.org/drawingml/2006/main';
        $p = 'http://schemas.openxmlformats.org/presentationml/2006/main';
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('a', $a);
        $xpath->registerNamespace('p', $p);
        foreach ($xpath->query('//p:sp') as $sp) {
            $ph = $xpath->query('.//p:nvSpPr/p:nvPr/p:ph', $sp)->item(0);
            if (!$ph instanceof \DOMElement) {
                continue;
            }
            $sizes = self::level_sizes($xpath, $xpath->query('.//a:lstStyle', $sp)->item(0));
            if (!$sizes) {
                continue;
            }
            if ($ph->hasAttribute('idx')) {
                $byidx[$ph->getAttribute('idx')] = $sizes;
            }
            $bytype[$ph->getAttribute('type')] = $sizes;
        }
        return [$byidx, $bytype];
    }

    /**
     * Parses a slide master's title/body/other text styles into level size maps.
     *
     * @param string|false $xml The master XML, or false.
     * @return array Keyed 'title'/'body'/'other', each level => sz.
     */
    private static function parse_master_sizes($xml): array {
        $out = [];
        $doc = new \DOMDocument();
        if ($xml === false || @$doc->loadXML($xml) === false) {
            return $out;
        }
        $a = 'http://schemas.openxmlformats.org/drawingml/2006/main';
        $p = 'http://schemas.openxmlformats.org/presentationml/2006/main';
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('a', $a);
        $xpath->registerNamespace('p', $p);
        foreach (['title' => 'titleStyle', 'body' => 'bodyStyle', 'other' => 'otherStyle'] as $key => $tag) {
            $style = $xpath->query('//p:txStyles/p:' . $tag, $doc)->item(0);
            $sizes = self::level_sizes($xpath, $style);
            if ($sizes) {
                $out[$key] = $sizes;
            }
        }
        return $out;
    }

    /**
     * Parses presentation.xml's defaultTextStyle into a level size map.
     *
     * @param string|false $xml The presentation XML, or false.
     * @return array level => sz.
     */
    private static function parse_default_text_style($xml): array {
        $doc = new \DOMDocument();
        if ($xml === false || @$doc->loadXML($xml) === false) {
            return [];
        }
        $a = 'http://schemas.openxmlformats.org/drawingml/2006/main';
        $p = 'http://schemas.openxmlformats.org/presentationml/2006/main';
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('a', $a);
        $xpath->registerNamespace('p', $p);
        return self::level_sizes($xpath, $xpath->query('//p:defaultTextStyle', $doc)->item(0));
    }

    /**
     * Reads a:lvlNpPr/a:defRPr@sz for each level under a style container.
     *
     * @param \DOMXPath $xpath A path bound to the drawing namespaces.
     * @param \DOMNode|null $container The a:lstStyle or p:*Style element, or null.
     * @return array level (0-based) => sz in hundredths of a point.
     */
    private static function level_sizes(\DOMXPath $xpath, ?\DOMNode $container): array {
        $sizes = [];
        if (!$container instanceof \DOMElement) {
            return $sizes;
        }
        for ($level = 0; $level < 9; $level++) {
            $sz = $xpath->query('a:lvl' . ($level + 1) . 'pPr/a:defRPr/@sz', $container)->item(0);
            if ($sz !== null) {
                $sizes[$level] = (int) $sz->nodeValue;
            }
        }
        return $sizes;
    }

    /**
     * Estimates the font scale that would let a text body fit its box height.
     *
     * The estimate wraps each paragraph by average glyph advance and sums the line
     * advances; when that exceeds the box's usable height the ratio (with a small
     * safety margin) becomes the scale, clamped to a sane floor. It intentionally
     * errs towards shrinking a little more rather than leaving text overflowing.
     *
     * @param \DOMXPath $xpath A path bound to the slide's namespaces.
     * @param \DOMNode $bodypr The a:bodyPr element (source of text insets).
     * @param \DOMNode $txbody The p:txBody element holding the paragraphs.
     * @param int $cx Box width in EMU.
     * @param int $cy Box height in EMU.
     * @param string $fontpath A TTF used to measure text, or '' to estimate widths.
     * @return float A scale in (0, 1]; 1.0 means the body already fits.
     */
    private static function estimate_fit_scale(
        \DOMXPath $xpath,
        \DOMNode $bodypr,
        \DOMNode $txbody,
        int $cx,
        int $cy,
        string $fontpath = ''
    ): float {
        // Vertical text swaps the wrapping and line-advance axes; the horizontal
        // model below does not apply, so such a body is left untouched.
        $nowrap = false;
        if ($bodypr instanceof \DOMElement) {
            $vert = $bodypr->getAttribute('vert');
            if ($vert !== '' && $vert !== 'horz') {
                return 1.0;
            }
            $nowrap = $bodypr->getAttribute('wrap') === 'none';
        }
        $emuperpt = 12700.0;
        $lins = self::inset($bodypr, 'lIns', 91440);
        $rins = self::inset($bodypr, 'rIns', 91440);
        $tins = self::inset($bodypr, 'tIns', 45720);
        $bins = self::inset($bodypr, 'bIns', 45720);
        $innerwidthpt = ($cx - $lins - $rins) / $emuperpt;
        $innerheightpt = ($cy - $tins - $bins) / $emuperpt;
        if ($innerwidthpt <= 0 || $innerheightpt <= 0) {
            return 1.0;
        }
        $paras = self::collect_paragraphs($xpath, $txbody, $innerwidthpt, $emuperpt);
        if (!$paras) {
            return 1.0;
        }
        $budget = $innerheightpt * self::FIT_TARGET_FILL;
        if (self::body_fits($paras, 1.0, $fontpath, $budget, $nowrap)) {
            return 1.0;
        }
        // Shrinking the font also reduces how many lines each paragraph wraps to,
        // so the full-size line count does not hold at smaller sizes. Search for
        // the largest scale whose re-wrapped height (and width, for no-wrap
        // bodies) fits, rather than scaling the full-size height linearly (which
        // over-shrinks marginal overflows).
        $lo = self::FIT_MIN_SCALE;
        $hi = 1.0;
        for ($i = 0; $i < 8; $i++) {
            $mid = ($lo + $hi) / 2.0;
            if (self::body_fits($paras, $mid, $fontpath, $budget, $nowrap)) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    /**
     * Extracts the fit-relevant metadata for each paragraph of a text body.
     *
     * @param \DOMXPath $xpath A path bound to the slide's namespaces.
     * @param \DOMNode $txbody The p:txBody element holding the paragraphs.
     * @param float $innerwidthpt The box's usable width in points.
     * @param float $emuperpt EMU per point.
     * @return array One entry per paragraph, each with keys: size (float pt),
     *     avail (float pt), segments (string[]), and lnspc/before/after (each an
     *     array with keys unit and val, or null).
     */
    private static function collect_paragraphs(
        \DOMXPath $xpath,
        \DOMNode $txbody,
        float $innerwidthpt,
        float $emuperpt
    ): array {
        $paras = [];
        foreach ($xpath->query('a:p', $txbody) as $para) {
            $ppr = $xpath->query('a:pPr', $para)->item(0);
            $marl = 0;
            if ($ppr instanceof \DOMElement && $ppr->hasAttribute('marL')) {
                $marl = (int) $ppr->getAttribute('marL');
            }
            $availpt = $innerwidthpt - ($marl / $emuperpt);
            if ($availpt <= 0) {
                $availpt = $innerwidthpt;
            }
            $paras[] = [
                'size' => self::resolve_para_size($xpath, $para, $txbody, $ppr),
                'avail' => $availpt,
                'segments' => self::paragraph_segments($para),
                'lnspc' => self::spacing_spec($ppr, 'lnSpc'),
                'before' => self::spacing_spec($ppr, 'spcBef'),
                'after' => self::spacing_spec($ppr, 'spcAft'),
            ];
        }
        return $paras;
    }

    /**
     * Decides whether a body fits its box height (and width, when it cannot wrap)
     * at a given font scale.
     *
     * @param array $paras Metadata from collect_paragraphs().
     * @param float $scale The font scale to evaluate (1.0 = full size).
     * @param string $fontpath A measurable TTF path, or '' to use the estimate.
     * @param float $budget The usable box height in points.
     * @param bool $nowrap Whether the body's a:bodyPr sets wrap="none".
     * @return bool True when the body fits at this scale.
     */
    private static function body_fits(array $paras, float $scale, string $fontpath, float $budget, bool $nowrap): bool {
        if (self::fit_needed_height($paras, $scale, $fontpath, $nowrap) > $budget) {
            return false;
        }
        if (!$nowrap) {
            return true;
        }
        // A wrap="none" body keeps every line intact, so the widest line must fit.
        foreach ($paras as $para) {
            $sizept = $para['size'] * $scale;
            foreach ($para['segments'] as $segment) {
                if (self::measured_width_pt($segment, $sizept, $fontpath) > $para['avail']) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Sums the rendered height of every paragraph at a given font scale.
     *
     * @param array $paras Metadata from collect_paragraphs().
     * @param float $scale The font scale to evaluate (1.0 = full size).
     * @param string $fontpath A measurable TTF path, or '' to use the estimate.
     * @param bool $nowrap Whether the body keeps each segment on one line.
     * @return float The total height in points.
     */
    private static function fit_needed_height(array $paras, float $scale, string $fontpath, bool $nowrap = false): float {
        $total = 0.0;
        foreach ($paras as $para) {
            $sizept = $para['size'] * $scale;
            $lineadvance = self::line_advance_pt($para['lnspc'], $sizept);
            $lines = 0;
            foreach ($para['segments'] as $segment) {
                $lines += $nowrap ? 1 : self::count_wrapped_lines($segment, $sizept, $para['avail'], $fontpath);
            }
            $total += max(1, $lines) * $lineadvance;
            $total += self::spacing_value_pt($para['before'], $sizept);
            $total += self::spacing_value_pt($para['after'], $sizept);
        }
        return $total;
    }

    /**
     * The height of one line for a font size, honouring declared line spacing.
     *
     * @param array|null $lnspc Line-spacing spec (keys unit, val), or null.
     * @param float $sizept The (already scaled) font size in points.
     * @return float The line advance in points.
     */
    private static function line_advance_pt(?array $lnspc, float $sizept): float {
        if ($lnspc === null) {
            return $sizept * self::FIT_LINE_HEIGHT;
        }
        if ($lnspc['unit'] === 'pts') {
            // An exact point spacing is a fixed line height regardless of size.
            return $lnspc['val'];
        }
        // A percentage is relative to the single-line height.
        return $sizept * self::FIT_LINE_HEIGHT * $lnspc['val'];
    }

    /**
     * Splits a paragraph into the text segments its forced line breaks produce.
     *
     * A soft break (a:br, i.e. Shift+Enter) starts a new rendered line even when
     * the text would otherwise fit on one, so each break bounds a segment.
     *
     * @param \DOMNode $para The a:p element.
     * @return string[] One string per forced line (at least one, possibly empty).
     */
    private static function paragraph_segments(\DOMNode $para): array {
        $segments = [];
        $current = '';
        foreach ($para->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            if ($child->localName === 'br') {
                $segments[] = $current;
                $current = '';
                continue;
            }
            // Runs (a:r) and text fields (a:fld) both carry a:t text.
            if ($child->localName === 'r' || $child->localName === 'fld') {
                foreach ($child->getElementsByTagName('t') as $t) {
                    $current .= $t->textContent;
                }
            }
        }
        $segments[] = $current;
        return $segments;
    }

    /**
     * Resolves a paragraph's font size in points through the inheritance chain.
     *
     * Run overrides win; then the paragraph's own default; then the end-paragraph
     * mark; then the body's list style for the paragraph's indent level. Layout
     * and master placeholder styles are not consulted (those bodies inherit their
     * geometry too, so they are skipped before reaching here), and a body default
     * is the final fallback.
     *
     * @param \DOMXPath $xpath A path bound to the slide's namespaces.
     * @param \DOMNode $para The a:p element.
     * @param \DOMNode $txbody The p:txBody element (for its a:lstStyle).
     * @param \DOMElement|null $ppr The paragraph's a:pPr, or null.
     * @return float The font size in points.
     */
    private static function resolve_para_size(
        \DOMXPath $xpath,
        \DOMNode $para,
        \DOMNode $txbody,
        ?\DOMElement $ppr
    ): float {
        $szs = [];
        foreach ($xpath->query('.//a:rPr/@sz | a:pPr/a:defRPr/@sz | a:endParaRPr/@sz', $para) as $szattr) {
            $szs[] = (int) $szattr->nodeValue;
        }
        if ($szs) {
            return max($szs) / 100.0;
        }
        $level = 0;
        if ($ppr instanceof \DOMElement && $ppr->hasAttribute('lvl')) {
            $level = (int) $ppr->getAttribute('lvl');
        }
        $lvlprops = $xpath->query('a:lstStyle/a:lvl' . ($level + 1) . 'pPr/a:defRPr/@sz', $txbody)->item(0);
        if ($lvlprops !== null) {
            return ((int) $lvlprops->nodeValue) / 100.0;
        }
        return self::FIT_DEFAULT_SZ / 100.0;
    }

    /**
     * Reads a spacing element (a:lnSpc/a:spcBef/a:spcAft) into a unit/value pair.
     *
     * @param \DOMElement|null $ppr The paragraph's a:pPr, or null.
     * @param string $tag The spacing element's local name.
     * @return array|null Keys unit ('pts'/'pct') and val — points for 'pts', a
     *     fraction (1.0 = 100%) for 'pct' — or null when the element is absent.
     */
    private static function spacing_spec(?\DOMElement $ppr, string $tag): ?array {
        if (!$ppr instanceof \DOMElement) {
            return null;
        }
        $a = 'http://schemas.openxmlformats.org/drawingml/2006/main';
        $spc = $ppr->getElementsByTagNameNS($a, $tag)->item(0);
        if (!$spc instanceof \DOMElement) {
            return null;
        }
        $pts = $spc->getElementsByTagNameNS($a, 'spcPts')->item(0);
        if ($pts instanceof \DOMElement && $pts->hasAttribute('val')) {
            return ['unit' => 'pts', 'val' => ((int) $pts->getAttribute('val')) / 100.0];
        }
        $pct = $spc->getElementsByTagNameNS($a, 'spcPct')->item(0);
        if ($pct instanceof \DOMElement && $pct->hasAttribute('val')) {
            return ['unit' => 'pct', 'val' => ((int) $pct->getAttribute('val')) / 100000.0];
        }
        return null;
    }

    /**
     * Converts a space-before/after spec to points at a given font size.
     *
     * @param array|null $spec A spacing spec (keys unit, val), or null.
     * @param float $sizept The (already scaled) font size in points.
     * @return float The spacing in points (0 when none).
     */
    private static function spacing_value_pt(?array $spec, float $sizept): float {
        if ($spec === null) {
            return 0.0;
        }
        return $spec['unit'] === 'pts' ? $spec['val'] : $spec['val'] * $sizept;
    }

    /**
     * Counts the display lines a paragraph wraps to within an available width.
     *
     * Words are wrapped greedily, matching how LibreOffice lays the line out, using
     * measured widths (GD when a metric font is available, else an em estimate).
     * A single token wider than the line is broken across lines. An empty segment
     * still occupies one line.
     *
     * @param string $text The segment's plain text.
     * @param float $sizept The font size in points.
     * @param float $availpt The usable line width in points.
     * @param string $fontpath A measurable TTF path, or '' to use the estimate.
     * @return int The line count (at least 1).
     */
    private static function count_wrapped_lines(string $text, float $sizept, float $availpt, string $fontpath): int {
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (!$words || $availpt <= 0 || $sizept <= 0) {
            return 1;
        }
        $lines = 0;
        $current = '';
        foreach ($words as $word) {
            // A single token wider than the line (LibreOffice breaks it mid-word,
            // e.g. at a slash) spans several lines on its own.
            $wordwidth = self::measured_width_pt($word, $sizept, $fontpath);
            if ($wordwidth > $availpt) {
                if ($current !== '') {
                    $lines++;
                    $current = '';
                }
                $lines += max(1, (int) ceil($wordwidth / $availpt));
                continue;
            }
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($current !== '' && self::measured_width_pt($candidate, $sizept, $fontpath) > $availpt) {
                $lines++;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $lines++;
        }
        return max(1, $lines);
    }

    /**
     * Measures a string's rendered width in points at a font size.
     *
     * Uses GD when a metric font is given; otherwise estimates from per-character
     * em widths (full-width glyphs such as CJK count as a full em, Latin as about
     * half), so a font-less host does not badly undercount wide scripts.
     *
     * @param string $text The text to measure.
     * @param float $sizept The font size in points.
     * @param string $fontpath An existing TTF path, or '' to estimate.
     * @return float The width in points.
     */
    private static function measured_width_pt(string $text, float $sizept, string $fontpath): float {
        if ($fontpath !== '') {
            $box = @imagettfbbox($sizept, 0, $fontpath, $text);
            if (is_array($box)) {
                // GD rasterises the point size at FIT_DPI; convert the pixel width
                // back to points so it compares against the box (also in points).
                return abs($box[2] - $box[0]) * 72.0 / self::FIT_DPI;
            }
        }
        return self::estimate_width_em($text) * $sizept;
    }

    /**
     * Estimates a string's width in em units for the font-less fallback.
     *
     * @param string $text The text to measure.
     * @return float The width in em.
     */
    private static function estimate_width_em(string $text): float {
        $chars = function_exists('mb_str_split') ? mb_str_split($text) : str_split($text);
        $width = 0.0;
        foreach ($chars as $char) {
            $width += self::is_fullwidth($char) ? self::FIT_FULLWIDTH_EM : self::FIT_CHAR_WIDTH;
        }
        return $width;
    }

    /**
     * Whether a character occupies a full em cell (CJK and other wide scripts).
     *
     * @param string $char A single (possibly multibyte) character.
     * @return bool True for full-width glyphs.
     */
    private static function is_fullwidth(string $char): bool {
        if (!function_exists('mb_ord')) {
            return false;
        }
        $cp = @mb_ord($char, 'UTF-8');
        if ($cp === false) {
            return false;
        }
        return ($cp >= 0x1100 && $cp <= 0x115F)      // Hangul Jamo.
            || ($cp >= 0x2E80 && $cp <= 0xA4CF)      // CJK radicals … Yi.
            || ($cp >= 0xAC00 && $cp <= 0xD7A3)      // Hangul syllables.
            || ($cp >= 0xF900 && $cp <= 0xFAFF)      // CJK compatibility ideographs.
            || ($cp >= 0xFF00 && $cp <= 0xFF60)      // Fullwidth forms.
            || ($cp >= 0xFFE0 && $cp <= 0xFFE6)      // Fullwidth signs.
            || ($cp >= 0x20000 && $cp <= 0x3FFFD);   // CJK extension planes.
    }

    /**
     * Returns a TTF to measure with: the selected render font's file when it is
     * readable, otherwise the first available metric candidate, or '' if none.
     *
     * @param string $renderfont The forced render-font name, or ''.
     * @return string An existing TTF path, or '' when width must be estimated.
     */
    private static function fit_font_path(string $renderfont = ''): string {
        if (!function_exists('imagettfbbox')) {
            return '';
        }
        if (
            $renderfont !== '' && isset(self::FIT_FONT_FILES[$renderfont])
            && is_readable(self::FIT_FONT_FILES[$renderfont])
        ) {
            return self::FIT_FONT_FILES[$renderfont];
        }
        foreach (self::FIT_FONT_CANDIDATES as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }
        return '';
    }

    /**
     * Reads a text-inset attribute from a:bodyPr, in EMU.
     *
     * @param \DOMNode $bodypr The a:bodyPr element.
     * @param string $attr The inset attribute name (lIns/rIns/tIns/bIns).
     * @param int $default The OOXML default when the attribute is absent.
     * @return int The inset in EMU.
     */
    private static function inset(\DOMNode $bodypr, string $attr, int $default): int {
        if ($bodypr instanceof \DOMElement && $bodypr->hasAttribute($attr)) {
            return (int) $bodypr->getAttribute($attr);
        }
        return $default;
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
