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
 * Upload form for the PowerPoint import tool.
 *
 * @package    local_lessonimportpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonimportpptx\form;

/**
 * Presents the .pptx file picker and the Import button.
 */
class import_form extends \moodleform {
    /**
     * Defines the form elements.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;

        // PDF is only offered when the server has a working PDF renderer.
        $pdfenabled = !empty($this->_customdata['pdfenabled']);
        $accepted = $pdfenabled ? ['.pptx', '.pdf'] : ['.pptx'];
        $labelkey = $pdfenabled ? 'filewithpdf' : 'file';

        $mform->addElement(
            'filepicker',
            'pptxfile',
            get_string($labelkey, 'local_lessonimportpptx'),
            null,
            ['accepted_types' => $accepted, 'maxfiles' => 1]
        );
        $mform->addRule('pptxfile', null, 'required', null, 'client');
        $mform->addHelpButton('pptxfile', $labelkey, 'local_lessonimportpptx');

        // A quick read-out of which rendering features this server can offer, and
        // the binary any unavailable one still needs.
        $mform->addElement('static', 'availability', '', $this->availability_html());

        // How to import: editable HTML, or faithful slide images (LibreOffice).
        // The image mode is only offered when the render backend is available.
        if (!empty($this->_customdata['officeenabled'])) {
            $mform->addElement('select', 'importmode', get_string('optionimportmode', 'local_lessonimportpptx'), [
                'editable' => get_string('importmodeeditable', 'local_lessonimportpptx'),
                'images' => get_string('importmodeimages', 'local_lessonimportpptx'),
            ]);
            $mform->setDefault('importmode', 'editable');
            $mform->addHelpButton('importmode', 'optionimportmode', 'local_lessonimportpptx');
        } else {
            $mform->addElement('hidden', 'importmode', 'editable');
        }
        $mform->setType('importmode', PARAM_ALPHA);

        // Advanced, per-import options: kept on the form (rather than a site
        // admin settings page) so each deck can be imported with its own values.
        $mform->addElement('text', 'imagemaxdim', get_string('optionimagemaxdim', 'local_lessonimportpptx'));
        $mform->setType('imagemaxdim', PARAM_INT);
        $mform->setDefault('imagemaxdim', 1600);
        $mform->addHelpButton('imagemaxdim', 'optionimagemaxdim', 'local_lessonimportpptx');
        $mform->setAdvanced('imagemaxdim');

        // Editable-mode only: render plain image runs as a Bootstrap card group
        // (the same markup the tiny_bootstrap editor plugin inserts).
        $mform->addElement('advcheckbox', 'cardgroup', get_string('optioncardgroup', 'local_lessonimportpptx'));
        $mform->setType('cardgroup', PARAM_BOOL);
        $mform->setDefault('cardgroup', 0);
        $mform->addHelpButton('cardgroup', 'optioncardgroup', 'local_lessonimportpptx');
        $mform->setAdvanced('cardgroup');
        $mform->hideIf('cardgroup', 'importmode', 'eq', 'images');

        // Editable-mode only: keep SmartArt slides (which flatten to a bare list)
        // as faithful rendered images. Only offered when the render backend exists.
        if (!empty($this->_customdata['officeenabled'])) {
            $mform->addElement('advcheckbox', 'smartartimages', get_string('optionsmartartimages', 'local_lessonimportpptx'));
            $mform->setDefault('smartartimages', 0);
            $mform->addHelpButton('smartartimages', 'optionsmartartimages', 'local_lessonimportpptx');
            $mform->setAdvanced('smartartimages');
            $mform->hideIf('smartartimages', 'importmode', 'eq', 'images');
        } else {
            $mform->addElement('hidden', 'smartartimages', 0);
        }
        $mform->setType('smartartimages', PARAM_BOOL);

        // Render backend only: force a font on slides that are rendered to images
        // (faithful mode or kept SmartArt/complex slides), so a deck whose own
        // font is missing from the server does not overflow its text boxes.
        if (!empty($this->_customdata['officeenabled'])) {
            $fonts = ['' => get_string('renderfontkeep', 'local_lessonimportpptx')];
            foreach (\local_lessonimportpptx\office\renderer::RENDER_FONTS as $family) {
                $fonts[$family] = $family;
            }
            $mform->addElement('select', 'renderfont', get_string('optionrenderfont', 'local_lessonimportpptx'), $fonts);
            $mform->setDefault('renderfont', '');
            $mform->addHelpButton('renderfont', 'optionrenderfont', 'local_lessonimportpptx');
            $mform->setAdvanced('renderfont');
        } else {
            $mform->addElement('hidden', 'renderfont', '');
        }
        $mform->setType('renderfont', PARAM_TEXT);

        // Editable-mode only: force a point size on body text and on text that
        // sits beside an image, overriding the sizes carried over from the slide.
        $sizes = [0 => get_string('fontsizekeep', 'local_lessonimportpptx')];
        foreach ([12, 14, 16, 18, 20, 24, 28, 32, 36] as $pt) {
            $sizes[$pt] = get_string('fontsizeoption', 'local_lessonimportpptx', $pt);
        }
        $mform->addElement('select', 'bodysize', get_string('optionbodysize', 'local_lessonimportpptx'), $sizes);
        $mform->setType('bodysize', PARAM_INT);
        $mform->setDefault('bodysize', 0);
        $mform->addHelpButton('bodysize', 'optionbodysize', 'local_lessonimportpptx');
        $mform->setAdvanced('bodysize');
        $mform->hideIf('bodysize', 'importmode', 'eq', 'images');

        $mform->addElement('select', 'adjacentsize', get_string('optionadjacentsize', 'local_lessonimportpptx'), $sizes);
        $mform->setType('adjacentsize', PARAM_INT);
        $mform->setDefault('adjacentsize', 0);
        $mform->addHelpButton('adjacentsize', 'optionadjacentsize', 'local_lessonimportpptx');
        $mform->setAdvanced('adjacentsize');
        $mform->hideIf('adjacentsize', 'importmode', 'eq', 'images');

        $mform->addElement(
            'text',
            'sectioncolour',
            get_string('optionsectioncolour', 'local_lessonimportpptx'),
            ['size' => 8, 'maxlength' => 7]
        );
        $mform->setType('sectioncolour', PARAM_TEXT);
        $mform->setDefault('sectioncolour', '#442980');
        $mform->addHelpButton('sectioncolour', 'optionsectioncolour', 'local_lessonimportpptx');
        $mform->setAdvanced('sectioncolour');
        $mform->hideIf('sectioncolour', 'importmode', 'eq', 'images');

        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons(true, get_string('import', 'local_lessonimportpptx'));
    }

    /**
     * Builds the "rendering features on this server" read-out shown on the form.
     *
     * Lists the three binary-dependent features, each with a tick or cross, and —
     * when a feature is unavailable — the binaries it still needs.
     *
     * @return string The panel HTML.
     */
    private function availability_html(): string {
        $poppler = !empty($this->_customdata['popplerenabled']);
        $libreoffice = !empty($this->_customdata['libreofficeenabled']);
        // Missing binaries for the two features that need LibreOffice and poppler.
        $imagemissing = [];
        if (!$libreoffice) {
            $imagemissing[] = 'LibreOffice';
        }
        if (!$poppler) {
            $imagemissing[] = 'Poppler';
        }
        $rows = $this->availability_row(
            get_string('availabilitypdf', 'local_lessonimportpptx'),
            $poppler,
            $poppler ? [] : ['Poppler']
        );
        $rows .= $this->availability_row(
            get_string('availabilityfaithful', 'local_lessonimportpptx'),
            $poppler && $libreoffice,
            $imagemissing
        );
        $rows .= $this->availability_row(
            get_string('availabilitycomplex', 'local_lessonimportpptx'),
            $poppler && $libreoffice,
            $imagemissing
        );
        return '<div class="local-lessonimportpptx-availability mb-2">'
            . '<p class="fw-bold mb-1">' . get_string('availabilityheading', 'local_lessonimportpptx') . '</p>'
            . '<ul class="list-unstyled mb-0">' . $rows . '</ul></div>';
    }

    /**
     * Renders one availability row: a tick or cross, the feature name, and any
     * missing binaries.
     *
     * @param string $label The feature's display name.
     * @param bool $available Whether the feature can run on this server.
     * @param string[] $missing The binaries the feature still needs (empty if available).
     * @return string The row's list-item HTML.
     */
    private function availability_row(string $label, bool $available, array $missing): string {
        if ($available) {
            $status = get_string('availabilityyes', 'local_lessonimportpptx');
            $mark = '<span class="text-success" aria-hidden="true">&#10004;</span>';
            $note = '';
        } else {
            $status = get_string('availabilityno', 'local_lessonimportpptx');
            $mark = '<span class="text-danger" aria-hidden="true">&#10008;</span>';
            $note = ' <span class="text-muted">&mdash; '
                . get_string('availabilityrequires', 'local_lessonimportpptx', implode(' + ', $missing))
                . '</span>';
        }
        return '<li>' . $mark . ' <span class="visually-hidden">' . $status . ': </span>'
            . s($label) . $note . '</li>';
    }
}
