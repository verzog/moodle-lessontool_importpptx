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

        $mform->addElement('select', 'adjacentsize', get_string('optionadjacentsize', 'local_lessonimportpptx'), $sizes);
        $mform->setType('adjacentsize', PARAM_INT);
        $mform->setDefault('adjacentsize', 0);
        $mform->addHelpButton('adjacentsize', 'optionadjacentsize', 'local_lessonimportpptx');
        $mform->setAdvanced('adjacentsize');

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

        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons(true, get_string('import', 'local_lessonimportpptx'));
    }
}
