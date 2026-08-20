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
 * Strings for component local_lessonimportpptx.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['asyncqueued'] = 'This upload will create {$a} pages, so it is being imported in the background. The pages will appear in this lesson shortly.';
$string['audiounsupported'] = 'Your browser does not support the audio element.';
$string['clicktoenlarge'] = 'Click to enlarge';
$string['confirmimport'] = 'Import PowerPoint';
$string['confirmimportdetail'] = 'Create {$a} content pages in this lesson from the uploaded file? Existing pages will be kept and the new pages added after them.';
$string['continuebutton'] = 'Continue';
$string['errorlocked'] = 'Another import is currently writing to this lesson. Please wait for it to finish and try again.';
$string['errornopptx'] = 'The uploaded file is not a valid PowerPoint (.pptx) presentation.';
$string['errornoslides'] = 'No slides could be found in the uploaded presentation.';
$string['errorofficerender'] = 'The presentation could not be rendered to images. Please check the server\'s LibreOffice and PDF (poppler) tools.';
$string['errorpdfrender'] = 'The PDF could not be converted to images. Please check the server\'s PDF tools (poppler).';
$string['errorpdfunavailable'] = 'PDF import is not available on this site because the required PDF tools (poppler) were not found.';
$string['errorstrictooxml'] = 'This presentation was saved as "Strict Open XML". Please re-save it as a standard PowerPoint (.pptx) presentation and try again.';
$string['errortoolarge'] = 'The presentation contains a part that is too large to process safely.';
$string['errortoomanypages'] = 'The PDF contains too many pages to import ({$a->count}; the limit is {$a->max}).';
$string['errortoomanyslides'] = 'The presentation contains too many slides to import ({$a->count}; the limit is {$a->max}).';
$string['file'] = 'PowerPoint presentation';
$string['file_help'] = 'Upload a PowerPoint presentation in .pptx format. Each slide becomes one content page in the lesson, with its text, lists, tables and images converted to editable HTML and a Continue button that leads to the next page.';
$string['filewithpdf'] = 'PowerPoint or PDF file';
$string['filewithpdf_help'] = 'Upload a PowerPoint (.pptx) presentation or a PDF. Each PowerPoint slide becomes one editable content page. Each PDF page becomes one content page containing an image of the page.';
$string['fontsizekeep'] = 'Keep the slide\'s size';
$string['fontsizeoption'] = '{$a} pt';
$string['import'] = 'Import';
$string['importmodeeditable'] = 'Editable content (text and images)';
$string['importmodeimages'] = 'Faithful images (one image per slide)';
$string['importpptx'] = 'Import PowerPoint';
$string['importresult'] = 'Imported {$a} pages from the presentation.';
$string['lessonimportpptx:import'] = 'Import PowerPoint presentations into a lesson';
$string['optionadjacentsize'] = 'Text-beside-image size';
$string['optionadjacentsize_help'] = 'Force a point size on body text that is laid out beside an image, overriding the size carried over from the slide. Leave on "Keep the slide\'s size" to use the slide\'s own sizes. Applies to the editable import only.';
$string['optionbodysize'] = 'Body text size';
$string['optionbodysize_help'] = 'Force a point size on ordinary body text (text that is not beside an image), overriding the size carried over from the slide. Leave on "Keep the slide\'s size" to use the slide\'s own sizes. Applies to the editable import only.';
$string['optioncardgroup'] = 'Images as card group';
$string['optioncardgroup_help'] = 'When importing as editable content, render each run of two or more ordinary pictures as a Bootstrap card group — the same markup the tiny_bootstrap editor plugin inserts — instead of a plain image grid. Each picture becomes a card that opens a click-to-enlarge zoom, and a paired short caption becomes the card text. A picture that shares a row with text becomes a zoomable card too, so the zoom is kept when an image sits beside text. A slide whose only content is a single picture is still shown as a centred, height-capped figure rather than a one-card group. Reconstructed diagrams (SmartArt and shape flows) are unaffected. This option applies to the editable import only; it has no effect when importing as faithful images.';
$string['optionimagemaxdim'] = 'Maximum image dimension (px)';
$string['optionimagemaxdim_help'] = 'Images larger than this on their longest edge are down-scaled on import to keep the lesson lean. Enter 0 to keep the original images unchanged.';
$string['optionimportmode'] = 'Import as';
$string['optionimportmode_help'] = 'Editable content converts each slide to text and images you can keep editing. Faithful images renders each slide to a picture with LibreOffice, so it looks exactly as in PowerPoint (diagrams, SmartArt and artwork included) but is not editable. Faithful images is only offered when the server has LibreOffice and the PDF tools (poppler). This choice applies to PowerPoint uploads only; a PDF is always imported as one page image per page.';
$string['optionsectioncolour'] = 'Section panel colour';
$string['optionsectioncolour_help'] = 'Fallback colour (for example #442980) for the coloured plate on section-divider pages. The importer uses the colour detected on the slide when it can; this value is used only when no fill can be read.';
$string['optionsmartartimages'] = 'Keep complex slides as images';
$string['optionsmartartimages_help'] = 'Some slides do not survive the trip to editable HTML: a SmartArt diagram flattens to a bare bullet list, and a slide that is one large picture with caption labels positioned over it loses those labels to orphaned lines below the image. With this on, those slides are kept as faithful rendered images instead — any slide containing SmartArt, and any slide that is a single dominant picture overlaid with two or more short caption labels. Every other slide is still imported as editable content. This needs the same LibreOffice render backend as the "faithful images" mode, and applies to the editable import only.';
$string['pagetitle'] = 'Page {$a}';
$string['pluginname'] = 'PowerPoint import for Lesson';
$string['privacy:metadata'] = 'The PowerPoint import tool does not store any personal data of its own. It creates lesson pages and files, which are stored and managed by the Lesson activity. An uploaded presentation is staged temporarily while an import is confirmed or queued, and staged copies are deleted after the import (or automatically cleaned up when abandoned).';
$string['sectiondefault'] = 'Section';
$string['slidetitle'] = 'Slide {$a}';
$string['taskcleanup'] = 'Clean up abandoned PowerPoint import uploads';
$string['taskimport'] = 'Import a PowerPoint presentation into a lesson';
$string['taskinprogress'] = 'A PowerPoint import is already queued for this lesson. Please wait for it to finish before importing again.';
