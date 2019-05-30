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
 * Implementaton of the quizaccess_ldflow plugin.
 *
 * @package    quizaccess
 * @subpackage ldflow
 * @copyright  2019 David Aylmer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/accessrule/accessrulebase.php');


/**
 * A rule imposing the ldflow settings.
 *
 * @copyright  2019 David Aylmer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quizaccess_ldflow extends quiz_access_rule_base {

    const ENTRY_VIEW = 'view';
    const ENTRY_SUMMARY = 'summary';
    const ENTRY_REVIEW = 'review';
    const ENTRY_UNKNOWN = '';

    /**
     * Return an appropriately configured instance of this rule, if it is applicable
     * to the given quiz, otherwise return null.
     * @param quiz $quizobj information about the quiz in question.
     * @param int $timenow the time that should be considered as 'now'.
     * @param bool $canignoretimelimits whether the current user is exempt from
     *      time limits by the mod/quiz:ignoretimelimits capability.
     * @return quiz_access_rule_base|null the rule, if applicable, else null.
     */
    public static function make(quiz $quizobj, $timenow, $canignoretimelimits) {

        $quiz = $quizobj->get_quiz();

        if (!$quiz->skipviewonfirstattempt && !$quiz->skipsummary &&
            !$quiz->skipreview && !$quiz->skipreviewandreturn) {
            return null;
        }

        return new self($quizobj, $timenow);
    }

    /**
     * Whether the user should be blocked from starting a new attempt or continuing
     * an attempt now.
     * @return string false if access should be allowed, a message explaining the
     *      reason if access should be prevented.
     */
    public function prevent_access() {

        // NB. Other rules are still enforced by startattempt.php. Okay to redirect on view.php.

        // If the current page is view.php and a relevant rule option is set.
        if (self::get_entry_point() == self::ENTRY_VIEW &&
            ($this->quiz->skipviewonfirstattempt || $this->quiz->skipreviewandreturn)) {

            global $USER;
            $finishedattempts = quiz_get_user_attempts($this->quiz->id, $USER->id, quiz_attempt::FINISHED, true);
            $isfirstattempt = count($finishedattempts) == 0 ? true : false;

            if ($isfirstattempt && $this->quiz->skipviewonfirstattempt) {
                $url = $this->quizobj->start_attempt_url();

                // TODO:.
                if ($this->is_editor()) {
                    return false;
                } else {
                    redirect($url);
                }
            }

            // TODO: EXPERIMENTAL.
            // Most recent finished attempt is within 5 seconds, redirect to course page.
            if (!empty($finishedattempts)) {
                if ($this->timenow - end($finishedattempts)->timefinish < 5) {
                    if ($this->quiz->skipreviewandreturn) {
                        if ($this->is_editor()) {
                            return false;
                        } else {
                            redirect(new moodle_url('/course/view.php', array('id' => $this->quiz->course)));
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * This is called when the current attempt at the quiz is finished. This is
     * used, for example by the password rule, to clear the flag in the session.
     */
    public function current_attempt_finished() {
        if ($this->quiz->skipreviewandreturn) {
            if (!$this->is_editor()) {
                redirect(new moodle_url('/course/view.php', array('id' => $this->quiz->course)));
            }
        }
    }

    /**
     * Information, such as might be shown on the quiz view page, relating to this restriction.
     * There is no obligation to return anything. If it is not appropriate to tell students
     * about this rule, then just return ''.
     * @return mixed a message, or array of messages, explaining the restriction
     *         (may be '' if no message is appropriate).
     */
    public function description() {

        // Only show rules to content creators.
        if ($this->is_editor()) {
            $output = '' . PHP_EOL;

            $output .= html_writer::start_tag('div', array('style' => 'display:inline-block'));
            $output .= get_string('currentenabledsettingsfor', 'quizaccess_ldflow',
                            get_string('pluginname', 'quizaccess_ldflow')
            ) . PHP_EOL;

            $output .= html_writer::start_tag('ul', array('class' => 'text-left')) . PHP_EOL;
            if ($this->quiz->skipviewonfirstattempt) {
                $output .= html_writer::tag('li', get_string('skipviewonfirstattempt', 'quizaccess_ldflow')) . PHP_EOL;
            }
            if ($this->quiz->skipsummary) {
                $output .= html_writer::tag('li', get_string('skipsummary', 'quizaccess_ldflow')) . PHP_EOL;
            }
            if ($this->quiz->skipreview) {
                $output .= html_writer::tag('li', get_string('skipreview', 'quizaccess_ldflow')) . PHP_EOL;
            }
            if ($this->quiz->skipreviewandreturn) {
                $output .= html_writer::tag('li', get_string('skipreviewandreturn', 'quizaccess_ldflow' )) . PHP_EOL;
            }
            $output .= html_writer::end_tag('ul') . PHP_EOL;
            $output .= html_writer::end_tag('div') . PHP_EOL;
            return $output;
        }
        return '';
    }

    /**
     * If the user should be shown a different amount of time than $timenow - $this->end_time(), then
     * override this method.  This is useful if the time remaining is large enough to be omitted.
     * @param object $attempt the current attempt
     * @param int $timenow the time now. We don't use $this->timenow, so we can
     * give the user a more accurate indication of how much time is left.
     * @return mixed the time left in seconds (can be negative) or false if there is no limit.
     */
    public function time_left_display($attempt, $timenow) {

        if (self::get_entry_point() == self::ENTRY_SUMMARY && $this->quiz->skipsummary) {

            $attemptobj = quiz_create_attempt_handling_errors($attempt->id, null);

            // Redirect to review?
            if (!$this->quiz->skipreview) {
                if ($attemptobj->is_finished()) {
                    redirect($attemptobj->review_url());
                }
            }

            // Redirect to finish attempt.
            $options = array(
                'attempt' => $attemptobj->get_attemptid(),
                'finishattempt' => 1,
                'timeup' => 0,
                'slots' => '',
                'cmid' => $attemptobj->get_cmid(),
                'sesskey' => sesskey(),
            );
            redirect(new moodle_url($attemptobj->processattempt_url(), $options));
        }

        $endtime = $this->end_time($attempt);
        if ($endtime === false) {
            return false;
        }
        return $endtime - $timenow;
    }

    /**
     * @return boolean whether this rule requires that the attemp (and review)
     *      pages must be displayed in a pop-up window.
     */
    public function attempt_must_be_in_popup() {

        if (self::get_entry_point() == self::ENTRY_REVIEW && ($this->quiz->skipreview || $this->quiz->skipreviewandreturn)) {
            if ($this->quiz->skipreviewandreturn) {
                redirect(new moodle_url('/course/view.php', array('id' => $this->quiz->course)));
            } else {
                redirect(new moodle_url('/mod/quiz/view.php', array('id' => $this->quiz->cmid)));
            }
        }

        return false;
    }

    /**
     * @return array any options that are required for showing the attempt page
     *      in a popup window.
     */
    public function get_popup_options() {
        return array();
    }

    /**
     * Sets up the attempt (review or summary) page with any special extra
     * properties required by this rule. securewindow rule is an example of where
     * this is used.
     *
     * @param moodle_page $page the page object to initialise.
     */
    public function setup_attempt_page($page) {
        // Do nothing by default.
    }

    /**
     * It is possible for one rule to override other rules.
     *
     * The aim is that third-party rules should be able to replace sandard rules
     * if they want. See, for example MDL-13592.
     *
     * @return array plugin names of other rules that this one replaces.
     *      For example array('ipaddress', 'password').
     */
    public function get_superceded_rules() {

        // Disable list of rules.

        return array();
    }

    /**
     * Add any fields that this rule requires to the quiz settings form. This
     * method is called from {@link mod_quiz_mod_form::definition()}, while the
     * security seciton is being built.
     * @param mod_quiz_mod_form $quizform the quiz settings form that is being built.
     * @param MoodleQuickForm $mform the wrapped MoodleQuickForm.
     */
    public static function add_settings_form_fields(
        mod_quiz_mod_form $quizform, MoodleQuickForm $mform) {

        $mform->addElement('static', 'reducednavigation', get_string('reducednavigation', 'quizaccess_ldflow'),
            get_string('reducednavigation_description', 'quizaccess_ldflow'));
        $mform->addHelpButton('reducednavigation', 'reducednavigation', 'quizaccess_ldflow');

        $mform->addElement('checkbox', 'skipviewonfirstattempt', get_string('skipviewonfirstattempt', 'quizaccess_ldflow'));
        $mform->addHelpButton('skipviewonfirstattempt', 'skipviewonfirstattempt', 'quizaccess_ldflow');
        $mform->setDefault('skipviewonfirstattempt', false);

        $mform->addElement('checkbox', 'skipsummary', get_string('skipsummary', 'quizaccess_ldflow'));
        $mform->addHelpButton('skipsummary', 'skipsummary', 'quizaccess_ldflow');
        $mform->setDefault('skipsummary', false);

        $mform->addElement('checkbox', 'skipreview', get_string('skipreview', 'quizaccess_ldflow'));
        $mform->addHelpButton('skipreview', 'skipreview', 'quizaccess_ldflow');
        $mform->setDefault('skipreview', false);

        $mform->addElement('checkbox', 'skipreviewandreturn', get_string('skipreviewandreturn', 'quizaccess_ldflow'));
        $mform->addHelpButton('skipreviewandreturn', 'skipreviewandreturn', 'quizaccess_ldflow');
        $mform->setDefault('skipreviewandreturn', false);
    }

    /**
     * Save any submitted settings when the quiz settings form is submitted. This
     * is called from {@link quiz_after_add_or_update()} in lib.php.
     * @param object $quiz the data from the quiz form, including $quiz->id
     *      which is the id of the quiz being saved.
     */
    public static function save_settings($quiz) {
        global $DB;

        if (empty($quiz->skipviewonfirstattempt) && empty($quiz->skipsummary) &&
            empty($quiz->skipreview) && empty($quiz->skipreviewandreturn)) {
            $DB->delete_records('quizaccess_ldflow', array('quizid' => $quiz->id));
        } else {
            if ($record = $DB->get_record('quizaccess_ldflow', array('quizid' => $quiz->id))) {
                $record->skipviewonfirstattempt = !empty($quiz->skipviewonfirstattempt) ? 1 : 0;
                $record->skipsummary = !empty($quiz->skipsummary) ? 1 : 0;
                $record->skipreview = !empty($quiz->skipreview) ? 1 : 0;
                $record->skipreviewandreturn = !empty($quiz->skipreviewandreturn) ? 1 : 0;
                $DB->update_record('quizaccess_ldflow', $record);
            } else {
                $record = new stdClass();
                $record->quizid = $quiz->id;
                $record->skipviewonfirstattempt = !empty($quiz->skipviewonfirstattempt) ? 1 : 0;
                $record->skipsummary = !empty($quiz->skipsummary) ? 1 : 0;
                $record->skipreview = !empty($quiz->skipreview) ? 1 : 0;
                $record->skipreviewandreturn = !empty($quiz->skipreviewandreturn) ? 1 : 0;
                $DB->insert_record('quizaccess_ldflow', $record);
            }
        }
    }

    /**
     * Delete any rule-specific settings when the quiz is deleted. This is called
     * from {@link quiz_delete_instance()} in lib.php.
     * @param object $quiz the data from the database, including $quiz->id
     *      which is the id of the quiz being deleted.
     * @since Moodle 2.7.1, 2.6.4, 2.5.7
     */
    public static function delete_settings($quiz) {
        global $DB;
        $DB->delete_records('quizaccess_ldflow', array('quizid' => $quiz->id));
    }

    /**
     * Return the bits of SQL needed to load all the settings from all the access
     * plugins in one DB query. The easiest way to understand what you need to do
     * here is probalby to read the code of {@link quiz_access_manager::load_settings()}.
     *
     * If you have some settings that cannot be loaded in this way, then you can
     * use the {@link get_extra_settings()} method instead, but that has
     * performance implications.
     *
     * @param int $quizid the id of the quiz we are loading settings for. This
     *     can also be accessed as quiz.id in the SQL. (quiz is a table alisas for {quiz}.)
     * @return array with three elements:
     *     1. fields: any fields to add to the select list. These should be alised
     *        if neccessary so that the field name starts the name of the plugin.
     *     2. joins: any joins (should probably be LEFT JOINS) with other tables that
     *        are needed.
     *     3. params: array of placeholder values that are needed by the SQL. You must
     *        used named placeholders, and the placeholder names should start with the
     *        plugin name, to avoid collisions.
     */
    public static function get_settings_sql($quizid) {

            return array(
                'skipviewonfirstattempt, skipsummary, skipreview, skipreviewandreturn',
                'LEFT JOIN {quizaccess_ldflow} ldflow ON ldflow.quizid = quiz.id',
                array());
    }

    private function is_editor() {
        // Only show rules to content creators.
        $context = context_module::instance($this->quiz->cmid);
        return has_capability('moodle/course:manageactivities', $context);
    }

    private static function get_entry_point() {

        // Use debug_backtrace to find the calling php file.
        // It might have been nice to use the $PAGE global and check the pagetype rather than the backtrace,
        // but quiz/startattempt.php does things like the following:
        //
        // // This script should only ever be posted to, so set page URL to the view page.
        // $PAGE->set_url($quizobj->view_url());
        //
        // In addition, things like $_SERVER['HTTP_REFERRER'] are not definitive about the calling source.

        // Get the entry point.
        $callstack = debug_backtrace();
        $entrypoint = end($callstack)['file'];
        $filename = pathinfo($entrypoint)['filename'];

        switch ($filename) {
            case self::ENTRY_VIEW:
                return self::ENTRY_VIEW;
            case self::ENTRY_SUMMARY:
                return self::ENTRY_SUMMARY;
            case self::ENTRY_REVIEW:
                return self::ENTRY_REVIEW;

            default:
                return self::ENTRY_UNKNOWN;
        }
    }
}