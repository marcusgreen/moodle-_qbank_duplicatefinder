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
 * Search form for the duplicate questions report.
 *
 * @package    qbank_duplicatefinder
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbank_duplicatefinder;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Moodle form for the duplicate-finder report filters.
 */
class report_form extends \moodleform {
    /**
     * Define the form elements.
     */
    public function definition() {
        $mform = $this->_form;

        $categoryoptions  = $this->_customdata['categoryoptions'];
        $defaultthreshold = $this->_customdata['defaultthreshold'];

        // Category selector.
        $mform->addElement(
            'select',
            'categoryid',
            get_string('categoryid', 'qbank_duplicatefinder'),
            $categoryoptions
        );

        // Scope selector.
        $scopeoptions = [
            'category' => get_string('scope_category', 'qbank_duplicatefinder'),
            'context'  => get_string('scope_context', 'qbank_duplicatefinder'),
        ];
        $mform->addElement(
            'select',
            'scope',
            get_string('scope', 'qbank_duplicatefinder'),
            $scopeoptions
        );
        $mform->addHelpButton('scope', 'scope', 'qbank_duplicatefinder');

        // Threshold input.
        $mform->addElement(
            'text',
            'threshold',
            get_string('threshold', 'qbank_duplicatefinder')
        );
        $mform->setType('threshold', PARAM_INT);
        $mform->setDefault('threshold', $defaultthreshold);
        $mform->addRule('threshold', null, 'required', null, 'client');
        $mform->addRule('threshold', null, 'numeric', null, 'client');
        $mform->addHelpButton('threshold', 'threshold', 'qbank_duplicatefinder');

        // Submit button only (no cancel).
        $this->add_action_buttons(false, get_string('search', 'qbank_duplicatefinder'));
    }

    /**
     * Extra server-side validation.
     *
     * @param array $data  Submitted form values.
     * @param array $files Uploaded files (unused).
     * @return array       Validation errors keyed by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $threshold = (int) ($data['threshold'] ?? 0);
        if ($threshold < 1 || $threshold > 100) {
            $errors['threshold'] = get_string('invaliddata', 'error');
        }

        return $errors;
    }
}
