# Changelog

All notable changes to **local_lessonimportpptx** (PowerPoint import for Lesson)
are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/), and the project follows
semantic-ish versioning in `$plugin->release`. Routine follow-ups that only
satisfied the code checker (phpcs/phpdoc) are folded into the release they
belong to.

## 1.12.2 – 2026-08-26

### Added
- Internal groundwork for an alternative slide-image render backend: a
  `render_backend` interface (the existing LibreOffice renderer implements it)
  and a `slide_html_builder` that lays a parsed slide out on a fixed-size stage.
  These classes are not wired into any import path yet, so there is no change in
  behaviour; they exist as a seam for future work.

## 1.12.1 – 2026-08-24

### Added
- `LICENSE` (GNU GPL v3) and this changelog.

## 1.12.0 – 2026-08-24

### Changed
- Revised the render-font choices: dropped DejaVu Sans (not metric-compatible,
  so it left rendered text over- or undersized) and added Caladea, the
  metric-compatible substitute for Cambria. The dropdown now offers Carlito
  (Calibri/Aptos), Liberation Sans (Arial), Liberation Serif (Times) and
  Caladea (Cambria). DejaVu Sans remains only as an internal width-measurement
  fallback.

## 1.11.0 – 2026-08-23

### Added
- Automatic PowerPoint *Shrink text on overflow* handling for slides rendered
  to images. LibreOffice does not apply that shrink during headless conversion,
  so the importer reduces the real text sizes before rendering — by the scale
  PowerPoint stored, or by an estimate from the box geometry — resolving
  placeholder sizes through the layout, master and presentation defaults.

### Fixed
- Image-slide text that still overflowed its box (1.11.1), and image
  grids/card groups whose bottom row was clipped by a spurious horizontal
  scrollbar (1.11.2).

## 1.10.0 – 2026-08-20

### Added
- Card-zoom for images that sit beside text; a *Render font* option to force a
  metric-compatible font before rendering, fixing substituted-font overflow.

### Changed
- Editable-only options are hidden in faithful-image mode.

## 1.9.0 – 2026-08-19

### Added
- Option to keep SmartArt (and single-dominant-picture caption) slides as
  faithful rendered images instead of flattening them to editable text.

## 1.8.0 – 2026-08-19

### Added
- Import of embedded slide audio as an HTML5 player with gesture-triggered
  autoplay.

### Fixed
- A lone image renders as a centred figure even with the card-group option on;
  column text sits beside a picture even when its box slightly overhangs.

## 1.7.0 – 2026-08-19

### Changed
- Section-divider hero laid out with a responsive Bootstrap grid, full-height
  plate and stacked lede media.

### Fixed
- Dropped blank placeholder images and stopped hard-coding `aria-hidden` on
  zoom modals (accessibility).

## 1.6.0 – 2026-08-19

### Added
- Per-import font-size selectors for body text and image-adjacent text.

## 1.5.0 – 2026-08-18

### Changed
- Preserve slide font sizes and full-bleed image sizing on editable import.

## 1.4.0 – 2026-08-18

### Changed
- Lay text beside images using real shape geometry (position, extent and
  rotation) rather than reading order alone.

## 1.3.0 – 2026-08-18

### Added
- Faithful-image pages titled from each slide's own title.

## 1.2.0 – 2026-08-17

### Added
- *Import as* option: faithful slide images via LibreOffice, with a
  dependency-availability read-out and a non-blocking backend probe.

## 1.1.0 – 2026-08-17

### Added
- Reconstruction of shape diagrams (flows) as inline SVG.

## 1.0.0 – 2026-08-17

### Added
- Initial release: import a PowerPoint (.pptx) presentation into a Moodle
  Lesson as content pages, recovering slide text structure, images and layout,
  with an optional PDF backend, background import for large decks, and a
  scheduled cleanup of abandoned staged uploads. Ships as a local plugin
  because the Lesson module has no subplugin framework.
