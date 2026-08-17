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
 * Resolves DrawingML scheme colour names to concrete #RRGGBB values.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx\pptx;

/**
 * Maps a slide's scheme colour names (tx1, bg1, accent1 …) to hex colours by
 * following the slide → layout → master → theme relationship chain: the master's
 * colour map translates the slide-facing names to theme slots, and the theme's
 * colour scheme gives each slot its value. Falls back to sensible defaults when
 * a part cannot be read, so shape reconstruction never fails for want of a colour.
 */
class theme {
    /** @var string[] Standard slide→theme colour map, used when the master has none. */
    const DEFAULT_MAP = [
        'bg1' => 'lt1', 'tx1' => 'dk1', 'bg2' => 'lt2', 'tx2' => 'dk2',
        'accent1' => 'accent1', 'accent2' => 'accent2', 'accent3' => 'accent3',
        'accent4' => 'accent4', 'accent5' => 'accent5', 'accent6' => 'accent6',
        'hlink' => 'hlink', 'folHlink' => 'folHlink',
    ];

    /** @var string[] Theme-slot fallbacks (Office default palette) for a missing scheme. */
    const DEFAULT_SCHEME = [
        'dk1' => '000000', 'lt1' => 'FFFFFF', 'dk2' => '44546A', 'lt2' => 'E7E6E6',
        'accent1' => '4472C4', 'accent2' => 'ED7D31', 'accent3' => 'A5A5A5',
        'accent4' => 'FFC000', 'accent5' => '5B9BD5', 'accent6' => '70AD47',
        'hlink' => '0563C1', 'folHlink' => '954F72',
    ];

    /** @var array<string,string> Slide-facing name => theme slot (from the master's clrMap). */
    private array $map;

    /** @var array<string,string> Theme slot => #RRGGBB hex (no hash), from the theme scheme. */
    private array $scheme;

    /**
     * Constructor: resolves the colour map and scheme for one slide.
     *
     * @param package $package The open package.
     * @param string $slidepath Zip path of the slide part.
     */
    public function __construct(package $package, string $slidepath) {
        $masterpath = $this->trace($package, $slidepath, '#slideLayout\d+\.xml$#', '#slideMaster\d+\.xml$#');
        $this->map = $this->read_map($package, $masterpath);
        $themepath = $masterpath === null ? null
            : $this->rel_target($package, $masterpath, '#theme/theme\d+\.xml$#');
        $this->scheme = $this->read_scheme($package, $themepath ?? 'ppt/theme/theme1.xml');
    }

    /**
     * Resolves a scheme colour name to a #RRGGBB value.
     *
     * @param string $name A scheme name such as "tx1", "bg1" or "accent1".
     * @return string|null The #RRGGBB colour, or null if the name is unknown.
     */
    public function colour(string $name): ?string {
        if ($name === 'phClr') {
            // A placeholder colour with no style context; treat as unresolved.
            return null;
        }
        $slot = $this->map[$name] ?? $name;
        $hex = $this->scheme[$slot] ?? self::DEFAULT_SCHEME[$slot] ?? null;
        return $hex === null ? null : '#' . $hex;
    }

    /**
     * Follows two relationship hops (slide→layout, layout→master) to the master.
     *
     * @param package $package The open package.
     * @param string $slidepath Zip path of the slide.
     * @param string $layoutre Regex identifying the layout target.
     * @param string $masterre Regex identifying the master target.
     * @return string|null The master's zip path, or null if the chain breaks.
     */
    private function trace(package $package, string $slidepath, string $layoutre, string $masterre): ?string {
        $layout = $this->rel_target($package, $slidepath, $layoutre);
        if ($layout === null) {
            return null;
        }
        return $this->rel_target($package, $layout, $masterre);
    }

    /**
     * Returns the first relationship target of a part matching a pattern.
     *
     * @param package $package The open package.
     * @param string $partpath Zip path of the part whose rels are searched.
     * @param string $pattern Regex the target must match.
     * @return string|null The resolved target zip path, or null if none matches.
     */
    private function rel_target(package $package, string $partpath, string $pattern): ?string {
        foreach ($package->get_rels($partpath) as $target) {
            if (preg_match($pattern, $target)) {
                return $target;
            }
        }
        return null;
    }

    /**
     * Reads a slide master's colour map (clrMap), or the standard map if absent.
     *
     * @param package $package The open package.
     * @param string|null $masterpath Zip path of the master, or null.
     * @return array<string,string> Slide-facing name => theme slot.
     */
    private function read_map(package $package, ?string $masterpath): array {
        if ($masterpath === null) {
            return self::DEFAULT_MAP;
        }
        $doc = $package->get_xml($masterpath);
        if ($doc === null) {
            return self::DEFAULT_MAP;
        }
        $node = $doc->getElementsByTagNameNS(package::NS_P, 'clrMap')->item(0);
        if (!$node instanceof \DOMElement) {
            return self::DEFAULT_MAP;
        }
        $map = [];
        foreach (self::DEFAULT_MAP as $name => $default) {
            $slot = $node->getAttribute($name);
            $map[$name] = $slot !== '' ? $slot : $default;
        }
        return $map;
    }

    /**
     * Reads a theme's colour scheme (clrScheme) into slot => hex pairs.
     *
     * @param package $package The open package.
     * @param string $themepath Zip path of the theme part.
     * @return array<string,string> Theme slot => #RRGGBB hex (no hash).
     */
    private function read_scheme(package $package, string $themepath): array {
        $doc = $package->get_xml($themepath);
        if ($doc === null) {
            return self::DEFAULT_SCHEME;
        }
        $clrscheme = $doc->getElementsByTagNameNS(package::NS_A, 'clrScheme')->item(0);
        if (!$clrscheme instanceof \DOMElement) {
            return self::DEFAULT_SCHEME;
        }
        $scheme = [];
        foreach ($clrscheme->childNodes as $slot) {
            if (!$slot instanceof \DOMElement || $slot->namespaceURI !== package::NS_A) {
                continue;
            }
            $hex = $this->read_slot_hex($slot);
            if ($hex !== null) {
                $scheme[$slot->localName] = $hex;
            }
        }
        return $scheme + self::DEFAULT_SCHEME;
    }

    /**
     * Extracts the hex value from one clrScheme slot (srgbClr or sysClr lastClr).
     *
     * @param \DOMElement $slot A theme colour slot element (e.g. a:dk1, a:accent1).
     * @return string|null The six-hex-digit value (no hash), or null if unreadable.
     */
    private function read_slot_hex(\DOMElement $slot): ?string {
        foreach ($slot->childNodes as $clr) {
            if (!$clr instanceof \DOMElement) {
                continue;
            }
            if ($clr->localName === 'srgbClr') {
                $val = $clr->getAttribute('val');
            } else if ($clr->localName === 'sysClr') {
                $val = $clr->getAttribute('lastClr');
            } else {
                continue;
            }
            if (preg_match('/^[0-9A-Fa-f]{6}$/', $val)) {
                return strtoupper($val);
            }
        }
        return null;
    }
}
