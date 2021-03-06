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
 * Restore code for the quizaccess_ldflow plugin.
 *
 * @package   quizaccess_ldflow
 * @copyright 2019 David Aylmer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/mod/quiz/backup/moodle2/restore_mod_quiz_access_subplugin.class.php');

/**
 * Provides the information to restore the ldflow quiz access plugin.
 *
 * If this plugin is required, a single
 * <quizaccess_ldflow><required>1</required></quizaccess_ldflow> tag
 * will be in the XML, and this needs to be written to the DB. Otherwise, nothing
 * needs to be written to the DB.
 */
class restore_quizaccess_ldflow_subplugin extends restore_mod_quiz_access_subplugin {

    protected function define_quiz_subplugin_structure() {
        $paths = array();
        $elementname = $this->get_namefor('');
        $elementpath = $this->get_pathfor('/quizaccess_ldflow');
        $paths[] = new restore_path_element($elementname, $elementpath);
        return $paths;
    }

    /**
     * Processes the quizaccess_ldflow element, if it is in the file.
     * @param array $data the data read from the XML file.
     */
    public function process_quizaccess_ldflow($data) {
        global $DB;
        $data = (object)$data;
        $data->quizid = $this->get_new_parentid('quiz');
        $DB->insert_record('quizaccess_ldflow', $data);
    }
}
