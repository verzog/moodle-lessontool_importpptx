# PowerPoint import for Lesson (local_lessonimportpptx)

A [Moodle Lesson](https://docs.moodle.org/en/Lesson_activity) tool that imports
a PowerPoint presentation (`.pptx`) and creates **one editable content page per
slide**, in slide order, each with a **Continue** button that leads to the next
page. Text, lists, tables, SmartArt and images become ordinary Moodle HTML that
a teacher can keep editing in Atto or TinyMCE — not flat page images.

This is the Lesson-module counterpart of
[booktool_importpptx](https://github.com/verzog/moodle-booktool_importpptx),
sharing the same pure-PHP PowerPoint engine.

## Why it is a *local* plugin

Unlike the Book module (which has a `booktool` subplugin type), **the Lesson
module has no subplugin framework** — core Moodle defines no `lessontool`
plugin type, so a subplugin cannot be installed against mod_lesson. This
importer therefore ships as a standard **local plugin** that attaches an
"Import PowerPoint" action to each lesson's settings menu and writes ordinary
lesson pages through the module's own tables and file areas. Everything a
teacher sees behaves as if it were part of the Lesson activity.

## Why it runs anywhere

The PowerPoint importer is **pure PHP** for text, tables, SmartArt and raster
images: a `.pptx` is a ZIP of XML, read with PHP's bundled `ZipArchive` and
`DOMDocument`, so that path has no third-party libraries and works on shared and
locked-down hosting.

Two things are handled outside pure PHP, each optional and gated:

- **PDF import** rasterises pages to images and needs the `poppler-utils` binaries
  (`pdfinfo`, `pdftoppm`). When they are absent the PDF option simply does not appear.
- **Vector clip-art (WMF/EMF)** cannot be shown by browsers. A WMF that merely wraps
  a bitmap is unpacked in pure PHP with no dependency; a true vector metafile is
  converted to PNG using whichever of ImageMagick, LibreOffice or Inkscape is
  installed, and is dropped cleanly when none is (see "Vector clip-art" below).

Neither affects the core PowerPoint text/image path, which stays pure PHP.

## What it does

- **One slide → one content page** (a lesson "branch table"), using the slide
  order from `presentation.xml`. Pages are appended after any pages the lesson
  already has, linked linearly, and each gets a single **Continue** button that
  jumps to the next page — so the imported deck plays through in order and the
  last page ends the lesson.
- **Titles.** A slide's title placeholder becomes the page title. With no
  placeholder, a short leading line is promoted; otherwise the page is
  `Slide N`.
- **Reading order and columns.** Blocks are ordered top-to-bottom in half-inch
  rows and left-to-right within a row. When a row holds several blocks side by
  side — text beside an image, or two/three columns — they become even Bootstrap
  columns that keep the arrangement on desktop and stack on mobile.
- **Text.** A bulleted text box becomes a list that **keeps its outline**: indented
  bullets nest under their parent, so a heading with sub-points stays a heading with
  sub-points instead of flattening to one long flat list. A text box that switches
  bullets off becomes plain `<p>` paragraphs. Bold runs and line breaks are preserved;
  decorative one- or two-character badges, and footer / slide-number / date
  placeholders, are dropped as page furniture.
- **Images.** Pictures are saved into the lesson's own `page_contents` file area
  and referenced with `@@PLUGINFILE@@`. Both standalone pictures and images used
  as a shape's fill (styled frames and picture placeholders) are recovered. A
  lone image is centred and height-capped rather than stretched full width, and
  images can optionally be down-scaled on import.
- **Vector clip-art (WMF/EMF).** Older decks store clip-art as Windows metafiles,
  which browsers cannot display. A metafile that only wraps a bitmap is unpacked to
  PNG in pure PHP; a true vector metafile is converted to PNG when a converter
  (ImageMagick with a WMF/EMF delegate, LibreOffice, or Inkscape) is installed. When
  a figure cannot be converted it — and any layout container it would leave empty —
  is dropped, so the page never shows a broken image.
- **Image grids.** Consecutive images become a responsive Bootstrap grid (up to
  three across, two images split 50/50). A run of images preceded by the same
  number of short lines is captioned, each caption above its image.
- **SmartArt and tables.** SmartArt text is recovered as a list; tables become
  HTML tables with the first row as headers.
- **Shape diagrams.** A slide built from drawn shapes — labelled boxes connected
  by block arrows (a process or flow diagram) — is reconstructed as a single
  inline **SVG** figure, in pure PHP, preserving the boxes, their fill and
  outline colours (resolved through the deck's theme), the arrows and connectors,
  and each box's text. Reconstruction only triggers for genuinely labelled
  diagrams (two or more captioned boxes) on slides that are not led by a photo,
  so ordinary photo and bullet slides are unaffected and stray annotation shapes
  drawn over a picture are not turned into noise.
- **Section dividers.** A slide with a full-height coloured side panel (detected by
  geometry, using the slide's own fill colour) becomes a styled section page.
  Lesson has no page hierarchy, so — unlike the Book version — the following
  slides are not nested; the divider simply stands out visually in the flow.
- **Large decks.** Above a fixed slide threshold (30) the import runs as a
  background task, with a confirmation step before any pages are written.

## PDF import (optional)

When the `poppler-utils` binaries are available on the server, the import form also
accepts a `.pdf`. Because a PDF carries no reliable text or layout structure, each
page is **rendered to a web image** (WebP where GD supports it, otherwise JPEG) and
becomes one content page — one PDF page → one lesson page, in order. These pages
are images rather than editable HTML, so use PDF import when you want a faithful
page-by-page copy and PowerPoint import when you want editable text.

- Rendering is done with `pdfinfo` and `pdftoppm` at 150 DPI, invoked with argument
  arrays (never a shell string), so there is no command-injection surface.
- Images honour the same **Maximum image dimension** option as slide images.
- A hard cap of 500 pages guards against abusive uploads.
- If the binaries live outside the system path, set their directory in
  `$CFG->forced_plugin_settings['local_lessonimportpptx']['popplerpath']`.

## Requirements

- Moodle 5.0 or later
- PHP 8.2 or later
- The `zip`, `dom` and (for optional image down-scaling) `gd` PHP extensions
- **Optional, for PDF import only:** the `poppler-utils` package (`pdfinfo`,
  `pdftoppm`) and the `gd` extension

## Installation

Copy the plugin so it lives at `local/lessonimportpptx/` (or
`public/local/lessonimportpptx/` on Moodle 5.1+), then visit
**Site administration → Notifications** to complete the install.

## Usage

1. Open a Lesson activity as a teacher.
2. From the lesson's administration menu, choose **Import PowerPoint**.
3. Upload a `.pptx` file (or a `.pdf` when the PDF backend is available) and confirm
   the number of pages to create.
4. The new content pages are appended after any existing pages, each with a
   Continue button; review them under the lesson's **Edit** tab.

## Import as: editable content or faithful images

When the server has **LibreOffice** and the **poppler** tools, the import form
offers an **Import as** choice:

- **Editable content** (default) — the pure-PHP path described above: each slide
  becomes text, lists, tables, images and reconstructed diagrams you can keep
  editing, each on its own content page with a Continue button.
- **Faithful images** — each slide is rendered to a picture with headless
  LibreOffice (`.pptx` → PDF → one image per slide, reusing the PDF backend), so
  the page looks exactly as in PowerPoint — diagrams, SmartArt, gradients and
  bespoke artwork included. These pages are images, not editable text, so use
  this when fidelity matters more than editing.

LibreOffice is invoked with argument arrays (never a shell string), in a private
per-run profile, so there is no command-injection surface. If LibreOffice lives
outside the system path, set its directory in
`$CFG->forced_plugin_settings['local_lessonimportpptx']['libreofficepath']`. When
the tools are absent the option simply does not appear and import stays editable.

## Import options

The tunable options live on the import form itself, under **Show more**, so each
deck can be imported with its own values:

- **Maximum image dimension (px)** — down-scale images on import (`0` keeps
  originals). Default 1600.
- **Section panel colour** — fallback plate colour (e.g. `#442980`) used only
  when a section slide's own fill cannot be read.

Access to the importer is controlled by the `local/lessonimportpptx:import`
capability (allowed for editing teachers and managers by default). The
background-task threshold defaults to 30 slides.

## Honest limits

- PowerPoint gives **editable** pages; PDF gives **image** pages (one rendered
  page each) and needs `poppler-utils` on the server.
- Imported pages are linear content pages — the importer does not generate
  question pages, clusters or branching; teachers can add those afterwards with
  the lesson's own editor.
- Lesson has no page hierarchy, so section dividers are styled but not nested.
- SmartArt is flattened to a list — its hierarchy is not preserved.
- Shape-diagram reconstruction targets labelled box-and-arrow diagrams. It draws
  rectangles, rounded rectangles, ellipses, straight connectors and the four
  block-arrow directions; other custom geometry is approximated by its bounding
  box, and gradient/picture shape fills are not reproduced. A diagram that mixes
  shapes with a large photo, or whose boxes carry no text, is left as extracted
  text and images rather than reconstructed.
- Grids re-flow images into an even layout rather than reproducing a slide's exact
  geometry.
- Complex slides (overlapping shapes, charts, animations, embedded video, WordArt)
  are best-effort: text and raster images are recovered; bespoke visuals may not be.
- Section-divider detection relies on a consistent full-height side panel (or the
  section-header layout); decks without one import those slides as ordinary pages.

## Licence

2026 Vernon Spain.

This program is free software: you can redistribute it and/or modify it under the
terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this
program. If not, see <https://www.gnu.org/licenses/>.
