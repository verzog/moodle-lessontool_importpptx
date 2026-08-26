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
 * Render backend that rasterises slides via an external render service.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx\office;

use local_lessonimportpptx\pptx\package;
use local_lessonimportpptx\pptx\slide;
use local_lessonimportpptx\pptx\slide_html_builder;
use local_lessonimportpptx\graphics\converter;

/**
 * Produces one page image per slide by reconstructing each slide as HTML and
 * posting it to the configured render service, which screenshots it with a
 * headless browser. Unlike {@see renderer} this needs neither LibreOffice nor
 * poppler on the Moodle server; the untrusted presentation is parsed here in
 * PHP and only self-generated HTML (plus the slide's own images) leaves the
 * site.
 */
class cloud_renderer implements render_backend {
    /** @var int English Metric Units per CSS pixel (914400 EMU/inch ÷ 96 px/inch). */
    const EMU_PER_PX = 9525;

    /** @var int Seconds to wait for the render service before giving up. */
    const TIMEOUT = 120;

    /** @var string The service base URL (no trailing /v1/render). */
    private string $endpoint;

    /** @var string The bearer API key sent to the service. */
    private string $apikey;

    /**
     * Builds a renderer that talks to the given service.
     *
     * @param string $endpoint Service base URL.
     * @param string $apikey Bearer API key.
     */
    public function __construct(string $endpoint, string $apikey) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->apikey = $apikey;
    }

    /**
     * Whether an external render service is configured and enabled.
     *
     * @return bool True when the cloud backend can be used.
     */
    public static function is_configured(): bool {
        if (!get_config('local_lessonimportpptx', 'cloudenabled')) {
            return false;
        }
        $url = (string) get_config('local_lessonimportpptx', 'cloudurl');
        $key = (string) get_config('local_lessonimportpptx', 'cloudkey');
        return $url !== '' && $key !== '';
    }

    /**
     * Builds a cloud renderer from the plugin's admin settings.
     *
     * @return self
     */
    public static function from_config(): self {
        return new self(
            (string) get_config('local_lessonimportpptx', 'cloudurl'),
            (string) get_config('local_lessonimportpptx', 'cloudkey')
        );
    }

    /**
     * Renders each slide to a PNG via the external service.
     *
     * @param \stored_file $pptx The uploaded presentation file.
     * @param int $maxdim The longest edge of each rendered image, in pixels.
     * @param string $renderfont Ignored (the service renders with its own fonts).
     * @return \Generator Yields [int, string, string] page tuples.
     */
    public function render_pages(\stored_file $pptx, int $maxdim, string $renderfont = ''): \Generator {
        $dir = make_request_directory();
        $source = $dir . '/import.pptx';
        $pptx->copy_content_to($source);

        $package = new package($source);
        try {
            $slides = $this->build_slides($package, $maxdim);
        } finally {
            $package->close();
        }
        if (empty($slides)) {
            return;
        }

        $pages = $this->post_render(['slides' => $slides]);
        foreach ($pages as $page) {
            $index = (int) ($page['index'] ?? 0);
            $data = base64_decode((string) ($page['data'] ?? ''), true);
            if ($index > 0 && $data !== false && $data !== '') {
                yield [$index, 'slide-' . $index . '.png', $data];
            }
        }
    }

    /**
     * Reconstructs every slide as canvas HTML with its inlined image assets.
     *
     * @param package $package The open presentation package.
     * @param int $maxdim The target longest edge in pixels (0 for the native size).
     * @return array[] One payload entry per slide.
     */
    private function build_slides(package $package, int $maxdim): array {
        $width = $package->slide_width();
        $height = $package->slide_height();
        $scale = $this->scale_for($width, $height, $maxdim);

        $slides = [];
        foreach ($package->get_slide_paths() as $path) {
            $parsed = (new slide($package, $path))->parse();
            $out = (new slide_html_builder('#ffffff', $width, $height))->build($parsed);
            $slides[] = [
                'html' => $out->html,
                'width' => intdiv($width, self::EMU_PER_PX),
                'height' => intdiv($height, self::EMU_PER_PX),
                'scale' => $scale,
                'assets' => $this->collect_assets($package, $out->images),
            ];
        }
        return $slides;
    }

    /**
     * Chooses a device scale so the rendered image's longest edge matches maxdim.
     *
     * @param int $width Slide width in EMU.
     * @param int $height Slide height in EMU.
     * @param int $maxdim Target longest edge in pixels (0 leaves the native size).
     * @return float A scale factor clamped to a sane range.
     */
    private function scale_for(int $width, int $height, int $maxdim): float {
        $longest = intdiv(max($width, $height), self::EMU_PER_PX);
        if ($maxdim <= 0 || $longest <= 0) {
            return 2.0;
        }
        return max(0.5, min(4.0, round($maxdim / $longest, 3)));
    }

    /**
     * Gathers the base64 bytes for each image a slide references, converting
     * WMF/EMF vector art to PNG first (a browser cannot display those).
     *
     * @param package $package The open presentation package.
     * @param array $images Map of file-area name => source media path.
     * @return array Map of file-area name => base64-encoded image bytes.
     */
    private function collect_assets(package $package, array $images): array {
        $assets = [];
        foreach ($images as $name => $mediapath) {
            $bytes = $package->get_bytes($mediapath);
            if ($bytes === null) {
                continue;
            }
            $ext = strtolower((string) pathinfo($mediapath, PATHINFO_EXTENSION));
            if ($ext === 'wmf' || $ext === 'emf') {
                $bytes = converter::to_png($bytes, $ext);
                if ($bytes === null) {
                    continue;
                }
            }
            $assets[$name] = base64_encode($bytes);
        }
        return $assets;
    }

    /**
     * Posts the render request and returns the decoded pages array.
     *
     * @param array $payload The request body ({slides: [...]}).
     * @return array[] The service's pages array.
     */
    protected function post_render(array $payload): array {
        $curl = new \curl();
        $response = $curl->post($this->endpoint . '/v1/render', json_encode($payload), [
            'CURLOPT_HTTPHEADER' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apikey,
            ],
            'CURLOPT_TIMEOUT' => self::TIMEOUT,
        ]);
        $info = $curl->get_info();
        $code = (int) ($info['http_code'] ?? 0);
        if ($curl->get_errno() || $code < 200 || $code >= 300) {
            throw new \moodle_exception('errorcloudrender', 'local_lessonimportpptx');
        }
        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded) || !isset($decoded['pages']) || !is_array($decoded['pages'])) {
            throw new \moodle_exception('errorcloudrender', 'local_lessonimportpptx');
        }
        return $decoded['pages'];
    }
}
