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
 * Library of internal classes and functions for module workshop
 *
 * All the workshop specific functions, needed to implement the module
 * logic, should go to here. Instead of having bunch of function named
 * workshopplus_something() taking the workshop instance as the first
 * parameter, we use a class workshop that provides all methods.
 *
 * @package    mod_workshopplus
 * @copyright  2009 David Mudrak <david.mudrak@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

require_once(dirname(__FILE__).'/lib.php');     // we extend this library here
require_once($CFG->libdir . '/gradelib.php');   // we use some rounding and comparing routines here
require_once($CFG->libdir . '/filelib.php');
require_once(dirname(dirname(__FILE__)).'/workshop/locallib.php');     // we extend this library here

/**
 * Full-featured workshop API
 *
 * This wraps the workshop database record with a set of methods that are called
 * from the module itself. The class should be initialized right after you get
 * $workshop, $cm and $course records at the begining of the script.
 */
class workshopplus extends workshop {

    /**
     * Returns the list of all allocations (i.e. assigned assessments) in the workshop
     *
     * Assessments of example submissions are ignored
     *
     * @return array
     */
    public function get_allocations() {
        global $DB;

        $sql = 'SELECT a.id, a.submissionid, a.reviewerid, s.authorid
                  FROM {workshopplus_assessments} a
            INNER JOIN {workshopplus_submissions} s ON (a.submissionid = s.id)
                 WHERE s.example = 0 AND s.workshopid = :workshopid';
        $params = array('workshopid' => $this->id);

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Returns the total number of records that would be returned by {@link self::get_submissions()}
     *
     * @param mixed $authorid int|array|'all' If set to [array of] integer, return submission[s] of the given user[s] only
     * @param int $groupid If non-zero, return only submissions by authors in the specified group
     * @return int number of records
     */
    public function count_submissions($authorid='all', $groupid=0) {
        global $DB;

        $params = array('workshopid' => $this->id);
        $sql = "SELECT COUNT(s.id)
                  FROM {workshopplus_submissions} s
                  JOIN {user} u ON (s.authorid = u.id)";
        if ($groupid) {
            $sql .= " JOIN {groups_members} gm ON (gm.userid = u.id AND gm.groupid = :groupid)";
            $params['groupid'] = $groupid;
        }
        $sql .= " WHERE s.example = 0 AND s.workshopid = :workshopid";

        if ('all' === $authorid) {
            echo("TODO: fix empty if");
            // no additional conditions
        } else if (!empty($authorid)) {
            list($usql, $uparams) = $DB->get_in_or_equal($authorid, SQL_PARAMS_NAMED);
            $sql .= " AND authorid $usql";
            $params = array_merge($params, $uparams);
        } else {
            // $authorid is empty
            return 0;
        }

        return $DB->count_records_sql($sql, $params);
    }


    /**
     * Returns submissions from this workshop
     *
     * Fetches data from {workshopplus_submissions} and adds some useful information from other
     * tables. Does not return textual fields to prevent possible memory lack issues.
     *
     * @see self::count_submissions()
     * @param mixed $authorid int|array|'all' If set to [array of] integer, return submission[s] of the given user[s] only
     * @param int $groupid If non-zero, return only submissions by authors in the specified group
     * @param int $limitfrom Return a subset of records, starting at this point (optional)
     * @param int $limitnum Return a subset containing this many records in total (optional, required if $limitfrom is set)
     * @return array of records or an empty array
     */
    public function get_submissions($authorid='all', $groupid=0, $limitfrom=0, $limitnum=0) {
        global $DB;

        $authorfields      = user_picture::fields('u', null, 'authoridx', 'author');
        $gradeoverbyfields = user_picture::fields('t', null, 'gradeoverbyx', 'over');
        $params            = array('workshopid' => $this->id);
        $sql = "SELECT s.id, s.workshopid, s.example, s.authorid, s.timecreated, s.timemodified,
                       s.title, s.grade, s.gradeover, s.gradeoverby, s.published,
                       $authorfields, $gradeoverbyfields
                  FROM {workshopplus_submissions} s
                  JOIN {user} u ON (s.authorid = u.id)";
        if ($groupid) {
            $sql .= " JOIN {groups_members} gm ON (gm.userid = u.id AND gm.groupid = :groupid)";
            $params['groupid'] = $groupid;
        }
        $sql .= " LEFT JOIN {user} t ON (s.gradeoverby = t.id)
                 WHERE s.example = 0 AND s.workshopid = :workshopid";

        if ('all' === $authorid) {
            echo("TODO: fix empty if");
            // no additional conditions
        } else if (!empty($authorid)) {
            list($usql, $uparams) = $DB->get_in_or_equal($authorid, SQL_PARAMS_NAMED);
            $sql .= " AND authorid $usql";
            $params = array_merge($params, $uparams);
        } else {
            // $authorid is empty
            return array();
        }
        list($sort, $sortparams) = users_order_by_sql('u');
        $sql .= " ORDER BY $sort";

        return $DB->get_records_sql($sql, array_merge($params, $sortparams), $limitfrom, $limitnum);
    }

    /**
     * Returns a submission record with the author's data
     *
     * @param int $id submission id
     * @return stdclass
     */
    public function get_submission_by_id($id) {
        global $DB;

        // we intentionally check the workshopid here, too, so the workshop can't touch submissions
        // from other instances
        $authorfields      = user_picture::fields('u', null, 'authoridx', 'author');
        $gradeoverbyfields = user_picture::fields('g', null, 'gradeoverbyx', 'gradeoverby');
        $sql = "SELECT s.*, $authorfields, $gradeoverbyfields
                  FROM {workshopplus_submissions} s
            INNER JOIN {user} u ON (s.authorid = u.id)
             LEFT JOIN {user} g ON (s.gradeoverby = g.id)
                 WHERE s.example = 0 AND s.workshopid = :workshopid AND s.id = :id";
        $params = array('workshopid' => $this->id, 'id' => $id);
        return $DB->get_record_sql($sql, $params, MUST_EXIST);
    }

    /**
     * Returns a submission submitted by the given author
     *
     * @param int $id author id
     * @return stdclass|false
     */
    public function get_submission_by_author($authorid) {
        global $DB;

        if (empty($authorid)) {
            return false;
        }
        $authorfields      = user_picture::fields('u', null, 'authoridx', 'author');
        $gradeoverbyfields = user_picture::fields('g', null, 'gradeoverbyx', 'gradeoverby');
        $sql = "SELECT s.*, $authorfields, $gradeoverbyfields
                  FROM {workshopplus_submissions} s
            INNER JOIN {user} u ON (s.authorid = u.id)
             LEFT JOIN {user} g ON (s.gradeoverby = g.id)
                 WHERE s.example = 0 AND s.workshopid = :workshopid AND s.authorid = :authorid";
        $params = array('workshopid' => $this->id, 'authorid' => $authorid);
        return $DB->get_record_sql($sql, $params);
    }

    /**
     * Returns published submissions with their authors data
     *
     * @return array of stdclass
     */
    public function get_published_submissions($orderby='finalgrade DESC') {
        global $DB;

        $authorfields = user_picture::fields('u', null, 'authoridx', 'author');
        $sql = "SELECT s.id, s.authorid, s.timecreated, s.timemodified,
                       s.title, s.grade, s.gradeover, COALESCE(s.gradeover,s.grade) AS finalgrade,
                       $authorfields
                  FROM {workshopplus_submissions} s
            INNER JOIN {user} u ON (s.authorid = u.id)
                 WHERE s.example = 0 AND s.workshopid = :workshopid AND s.published = 1
              ORDER BY $orderby";
        $params = array('workshopid' => $this->id);
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Returns full record of the given example submission
     *
     * @param int $id example submission od
     * @return object
     */
    public function get_example_by_id($id) {
        global $DB;
        return $DB->get_record('workshopplus_submissions',
                array('id' => $id, 'workshopid' => $this->id, 'example' => 1), '*', MUST_EXIST);
    }

    /**
     * Returns the list of example submissions in this workshop with reference assessments attached
     *
     * @return array of objects or an empty array
     * @see workshop::prepare_example_summary()
     */
    public function get_examples_for_manager() {
        global $DB;

        $sql = 'SELECT s.id, s.title,
                       a.id AS assessmentid, a.grade, a.gradinggrade
                  FROM {workshopplus_submissions} s
             LEFT JOIN {workshopplus_assessments} a ON (a.submissionid = s.id AND a.weight = 1)
                 WHERE s.example = 1 AND s.workshopid = :workshopid
              ORDER BY s.title';
        return $DB->get_records_sql($sql, array('workshopid' => $this->id));
    }

    /**
     * Returns the list of all example submissions in this workshop with the information of assessments done by the given user
     *
     * @param int $reviewerid user id
     * @return array of objects, indexed by example submission id
     * @see workshop::prepare_example_summary()
     */
    public function get_examples_for_reviewer($reviewerid) {
        global $DB;

        if (empty($reviewerid)) {
            return false;
        }
        $sql = 'SELECT s.id, s.title,
                       a.id AS assessmentid, a.grade, a.gradinggrade
                  FROM {workshopplus_submissions} s
             LEFT JOIN {workshopplus_assessments} a ON (a.submissionid = s.id AND a.reviewerid = :reviewerid AND a.weight = 0)
                 WHERE s.example = 1 AND s.workshopid = :workshopid
              ORDER BY s.title';
        return $DB->get_records_sql($sql, array('workshopid' => $this->id, 'reviewerid' => $reviewerid));
    }

    /**
     * Returns the list of all assessments in the workshop with some data added
     *
     * Fetches data from {workshopplus_assessments} and adds some useful information from other
     * tables. The returned object does not contain textual fields (i.e. comments) to prevent memory
     * lack issues.
     *
     * @return array [assessmentid] => assessment stdclass
     */
    public function get_all_assessments() {
        global $DB;

        $reviewerfields = user_picture::fields('reviewer', null, 'revieweridx', 'reviewer');
        $authorfields   = user_picture::fields('author', null, 'authorid', 'author');
        $overbyfields   = user_picture::fields('overby', null, 'gradinggradeoverbyx', 'overby');
        list($sort, $params) = users_order_by_sql('reviewer');
        $sql = "SELECT a.id, a.submissionid, a.reviewerid, a.timecreated, a.timemodified,
                       a.grade, a.gradinggrade, a.gradinggradeover, a.gradinggradeoverby,
                       $reviewerfields, $authorfields, $overbyfields,
                       s.title
                  FROM {workshopplus_assessments} a
            INNER JOIN {user} reviewer ON (a.reviewerid = reviewer.id)
            INNER JOIN {workshopplus_submissions} s ON (a.submissionid = s.id)
            INNER JOIN {user} author ON (s.authorid = author.id)
             LEFT JOIN {user} overby ON (a.gradinggradeoverby = overby.id)
                 WHERE s.workshopid = :workshopid AND s.example = 0
              ORDER BY $sort";
        $params['workshopid'] = $this->id;

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Get the complete information about the given assessment
     *
     * @param int $id Assessment ID
     * @return stdclass
     */
    public function get_assessment_by_id($id) {
        global $DB;

        $reviewerfields = user_picture::fields('reviewer', null, 'revieweridx', 'reviewer');
        $authorfields   = user_picture::fields('author', null, 'authorid', 'author');
        $overbyfields   = user_picture::fields('overby', null, 'gradinggradeoverbyx', 'overby');
        $sql = "SELECT a.*, s.title, $reviewerfields, $authorfields, $overbyfields
                  FROM {workshopplus_assessments} a
            INNER JOIN {user} reviewer ON (a.reviewerid = reviewer.id)
            INNER JOIN {workshopplus_submissions} s ON (a.submissionid = s.id)
            INNER JOIN {user} author ON (s.authorid = author.id)
             LEFT JOIN {user} overby ON (a.gradinggradeoverby = overby.id)
                 WHERE a.id = :id AND s.workshopid = :workshopid";
        $params = array('id' => $id, 'workshopid' => $this->id);

        return $DB->get_record_sql($sql, $params, MUST_EXIST);
    }

    /**
     * Get the complete information about the user's assessment of the given submission
     *
     * @param int $sid submission ID
     * @param int $uid user ID of the reviewer
     * @return false|stdclass false if not found, stdclass otherwise
     */
    public function get_assessment_of_submission_by_user($submissionid, $reviewerid) {
        global $DB;

        $reviewerfields = user_picture::fields('reviewer', null, 'revieweridx', 'reviewer');
        $authorfields   = user_picture::fields('author', null, 'authorid', 'author');
        $overbyfields   = user_picture::fields('overby', null, 'gradinggradeoverbyx', 'overby');
        $sql = "SELECT a.*, s.title, $reviewerfields, $authorfields, $overbyfields
                  FROM {workshopplus_assessments} a
            INNER JOIN {user} reviewer ON (a.reviewerid = reviewer.id)
            INNER JOIN {workshopplus_submissions} s ON (a.submissionid = s.id AND s.example = 0)
            INNER JOIN {user} author ON (s.authorid = author.id)
             LEFT JOIN {user} overby ON (a.gradinggradeoverby = overby.id)
                 WHERE s.id = :sid AND reviewer.id = :rid AND s.workshopid = :workshopid";
        $params = array('sid' => $submissionid, 'rid' => $reviewerid, 'workshopid' => $this->id);

        return $DB->get_record_sql($sql, $params, IGNORE_MISSING);
    }

    /**
     * Get the complete information about all assessments of the given submission
     *
     * @param int $submissionid
     * @return array
     */
    public function get_assessments_of_submission($submissionid) {
        global $DB;

        $reviewerfields = user_picture::fields('reviewer', null, 'revieweridx', 'reviewer');
        $overbyfields   = user_picture::fields('overby', null, 'gradinggradeoverbyx', 'overby');
        list($sort, $params) = users_order_by_sql('reviewer');
        $sql = "SELECT a.*, s.title, $reviewerfields, $overbyfields
                  FROM {workshopplus_assessments} a
            INNER JOIN {user} reviewer ON (a.reviewerid = reviewer.id)
            INNER JOIN {workshopplus_submissions} s ON (a.submissionid = s.id)
             LEFT JOIN {user} overby ON (a.gradinggradeoverby = overby.id)
                 WHERE s.example = 0 AND s.id = :submissionid AND s.workshopid = :workshopid
              ORDER BY $sort";
        $params['submissionid'] = $submissionid;
        $params['workshopid']   = $this->id;

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Get the complete information about all assessments allocated to the given reviewer
     *
     * @param int $reviewerid
     * @return array
     */
    public function get_assessments_by_reviewer($reviewerid) {
        global $DB;

        $reviewerfields = user_picture::fields('reviewer', null, 'revieweridx', 'reviewer');
        $authorfields   = user_picture::fields('author', null, 'authorid', 'author');
        $overbyfields   = user_picture::fields('overby', null, 'gradinggradeoverbyx', 'overby');
        $sql = "SELECT a.*, $reviewerfields, $authorfields, $overbyfields,
                       s.id AS submissionid, s.title AS submissiontitle, s.timecreated AS submissioncreated,
                       s.timemodified AS submissionmodified
                  FROM {workshopplus_assessments} a
            INNER JOIN {user} reviewer ON (a.reviewerid = reviewer.id)
            INNER JOIN {workshopplus_submissions} s ON (a.submissionid = s.id)
            INNER JOIN {user} author ON (s.authorid = author.id)
             LEFT JOIN {user} overby ON (a.gradinggradeoverby = overby.id)
                 WHERE s.example = 0 AND reviewer.id = :reviewerid AND s.workshopid = :workshopid";
        $params = array('reviewerid' => $reviewerid, 'workshopid' => $this->id);

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Switch to a new workshop phase
     *
     * Modifies the underlying database record. You should terminate the script shortly after calling this.
     *
     * @param int $newphase new phase code
     * @return bool true if success, false otherwise
     */
    public function switch_phase($newphase) {
        global $DB;

        $known = $this->available_phases_list();
        if (!isset($known[$newphase])) {
            return false;
        }

        if (self::PHASE_CLOSED == $newphase) {
            // push the grades into the gradebook
            $workshop = new stdclass();
            foreach ($this as $property => $value) {
                $workshop->{$property} = $value;
            }
            $workshop->course     = $this->course->id;
            $workshop->cmidnumber = $this->cm->id;
            $workshop->modname    = 'workshopplus';
            workshopplus_update_grades($workshop);
        }

        $DB->set_field('workshopplus', 'phase', $newphase, array('id' => $this->id));
        $this->phase = $newphase;
        $eventdata = array(
            'objectid' => $this->id,
            'context' => $this->context,
            'other' => array(
                'workshopphase' => $this->phase
            )
        );
        $event = \mod_workshop\event\phase_switched::create($eventdata);
        $event->trigger();
        return true;
    }



    /**
     * Saves a raw grade for submission as calculated from the assessment form fields
     *
     * @param array $assessmentid assessment record id, must exists
     * @param mixed $grade        raw percentual grade from 0.00000 to 100.00000
     * @return false|float        the saved grade
     */
    public function set_peer_grade($assessmentid, $grade) {
        global $DB;

        if (is_null($grade)) {
            return false;
        }
        $data = new stdclass();
        $data->id = $assessmentid;
        $data->grade = $grade;
        $data->timemodified = time();
        $DB->update_record('workshopplus_assessments', $data);
        return $grade;
    }

    /**
     * Returns the list of all grading grades in the workshop with some data added
     *
     * Fetches data from {workshopplus_assessments} and adds some useful information from other
     * tables. The returned object does not contain textual fields (i.e. comments) to prevent memory
     * lack issues.
     *
     * @return array [assessmentid] => assessment stdclass
     */
    public function get_all_grading_grades() {
        global $DB;

        $reviewerfields = user_picture::fields('reviewer', null, 'revieweridx', 'reviewer');
        list($sort, $params) = users_order_by_sql('reviewer');
        $sql = "SELECT a.id, a.submissionid, a.reviewerid, a.gradinggrade, a.gradinggradeover,
                       $reviewerfields, s.title
                  FROM {workshopplus_assessments} a
            INNER JOIN {user} reviewer ON (a.reviewerid = reviewer.id)
            INNER JOIN {workshopplus_submissions} s ON (a.submissionid = s.id)
                 WHERE s.workshopid = :workshopid AND s.example = 1
              ORDER BY $sort";
        $params['workshopid'] = $this->id;

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Returns the list of all grading harshnesss in the workshop with some data added
     *
     * Fetches data from {workshopplus_assessments} and adds some useful information from other
     * tables. The returned object does not contain textual fields (i.e. comments) to prevent memory
     * lack issues.
     *
     * @return array [assessmentid] => assessment stdclass
     */
    public function get_all_harshness_scores() {
        global $DB;

        $reviewerfields = user_picture::fields('reviewer', null, 'revieweridx', 'reviewer');
        list($sort, $params) = users_order_by_sql('reviewer');
        $sql = "SELECT a.id, a.submissionid, a.reviewerid, a.gradingharshness,
                       $reviewerfields, s.title
                  FROM {workshopplus_assessments} a
            INNER JOIN {user} reviewer ON (a.reviewerid = reviewer.id)
            INNER JOIN {workshopplus_submissions} s ON (a.submissionid = s.id)
                 WHERE s.workshopid = :workshopid AND s.example = 1
              ORDER BY $sort";
        $params['workshopid'] = $this->id;

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Removes the submission and all relevant data
     *
     * @param stdClass $submission record to delete
     * @return void
     */
    public function delete_submission(stdclass $submission) {
        global $DB;

        $assessments = $DB->get_records('workshopplus_assessments', array('submissionid' => $submission->id), '', 'id');
        $this->delete_assessment(array_keys($assessments));

        $fs = get_file_storage();
        $fs->delete_area_files($this->context->id, 'mod_workshopplus', 'submission_content', $submission->id);
        $fs->delete_area_files($this->context->id, 'mod_workshopplus', 'submission_attachment', $submission->id);

        $DB->delete_records('workshopplus_submissions', array('id' => $submission->id));
    }

    /**
     * Delete assessment record or records.
     *
     * Removes associated records from the workshopplus_grades table, too.
     *
     * @param int|array $id assessment id or array of assessments ids
     * @todo Give grading strategy plugins a chance to clean up their data, too.
     * @return bool true
     *//**
     * Allocate a submission to a user for review
     *
     * @param stdClass $submission Submission object with at least id property
     * @param int $reviewerid User ID
     * @param int $weight of the new assessment, from 0 to 16
     * @param bool $bulk repeated inserts into DB expected
     * @return int ID of the new assessment or an error code {@link self::ALLOCATION_EXISTS} if the allocation already exists
     */
    public function add_allocation(stdclass $submission, $reviewerid, $weight=1, $bulk=false) {
        global $DB;

        if ($DB->record_exists('workshopplus_assessments', array('submissionid' => $submission->id, 'reviewerid' => $reviewerid))) {
            return self::ALLOCATION_EXISTS;
        }

        $weight = (int)$weight;
        if ($weight < 0) {
            $weight = 0;
        }
        if ($weight > 16) {
            $weight = 16;
        }

        $now = time();
        $assessment = new stdclass();
        $assessment->submissionid           = $submission->id;
        $assessment->reviewerid             = $reviewerid;
        $assessment->timecreated            = $now;         // do not set timemodified here
        $assessment->weight                 = $weight;
        $assessment->feedbackauthorformat   = editors_get_preferred_format();
        $assessment->feedbackreviewerformat = editors_get_preferred_format();

        return $DB->insert_record('workshopplus_assessments', $assessment, true, $bulk);
    }

    public function delete_assessment($id) {
        global $DB;

        if (empty($id)) {
            return true;
        }

        $fs = get_file_storage();

        if (is_array($id)) {
            $DB->delete_records_list('workshopplus_grades', 'assessmentid', $id);
            foreach ($id as $itemid) {
                $fs->delete_area_files($this->context->id, 'mod_workshopplus', 'overallfeedback_content', $itemid);
                $fs->delete_area_files($this->context->id, 'mod_workshopplus', 'overallfeedback_attachment', $itemid);
            }
            $DB->delete_records_list('workshopplus_assessments', 'id', $id);

        } else {
            $DB->delete_records('workshopplus_grades', array('assessmentid' => $id));
            $fs->delete_area_files($this->context->id, 'mod_workshopplus', 'overallfeedback_content', $id);
            $fs->delete_area_files($this->context->id, 'mod_workshopplus', 'overallfeedback_attachment', $id);
            $DB->delete_records('workshopplus_assessments', array('id' => $id));
        }

        return true;
    }

    /**
     * Returns instance of grading strategy class
     *
     * @return stdclass Instance of a grading strategy
     */
    public function grading_strategy_instance() {
        global $CFG;    // because we require other libs here

        if (is_null($this->strategyinstance)) {
            $strategylib = dirname(__FILE__) . '/form/' . $this->strategy . '/lib.php';
            if (is_readable($strategylib)) {
                require_once($strategylib);
            } else {
                throw new coding_exception('the grading forms subplugin must contain library ' . $strategylib);
            }
            $classname = 'workshopplus_' . $this->strategy . '_strategy';
            $this->strategyinstance = new $classname($this);
            if (!in_array('workshop_strategy', class_implements($this->strategyinstance))) {
                throw new coding_exception($classname . ' does not implement workshop_strategy interface');
            }
        }
        return $this->strategyinstance;
    }

    /**
     * Returns instance of grading evaluation class
     *
     * @return stdclass Instance of a grading evaluation
     */
    public function grading_evaluation_instance() {
        global $CFG;    // because we require other libs here

        if (is_null($this->evaluationinstance)) {
            if (empty($this->evaluation)) {
                $this->evaluation = 'best';
            }
            $evaluationlib = dirname(__FILE__) . '/eval/' . $this->evaluation . '/lib.php';
            if (is_readable($evaluationlib)) {
                require_once($evaluationlib);
            } else {
                // Fall back in case the subplugin is not available.
                $this->evaluation = 'best';
                $evaluationlib = dirname(__FILE__) . '/eval/' . $this->evaluation . '/lib.php';
                if (is_readable($evaluationlib)) {
                    require_once($evaluationlib);
                } else {
                    // Fall back in case the subplugin is not available any more.
                    throw new coding_exception('Missing default grading evaluation library ' . $evaluationlib);
                }
            }
            $classname = 'workshopplus_' . $this->evaluation . '_evaluation';
            $this->evaluationinstance = new $classname($this);
            if (!in_array('workshop_evaluation', class_parents($this->evaluationinstance))) {
                throw new coding_exception($classname . ' does not extend workshop_evaluation class');
            }
        }
        return $this->evaluationinstance;
    }

    /**
     * Returns instance of submissions allocator
     *
     * @param string $method The name of the allocation method, must be PARAM_ALPHA
     * @return stdclass Instance of submissions allocator
     */
    public function allocator_instance($method) {
        global $CFG;    // because we require other libs here

        $allocationlib = dirname(__FILE__) . '/allocation/' . $method . '/lib.php';
        if (is_readable($allocationlib)) {
            require_once($allocationlib);
        } else {
            throw new coding_exception('Unable to find the allocation library ' . $allocationlib);
        }
        $classname = 'workshopplus_' . $method . '_allocator';
        return new $classname($this);
    }

    /**
     * @return moodle_url of this workshop's view page
     */
    public function view_url() {
        global $CFG;
        return new moodle_url('/mod/workshopplus/view.php', array('id' => $this->cm->id));
    }

    /**
     * @return moodle_url of this workshop's view page
     */
    public function view_url2() {
        global $CFG;
        return new moodle_url('/mod/workshop/view.php', array('id' => $this->cm->id));
    }

    /**
     * @return moodle_url of the page for editing this workshop's grading form
     */
    public function editform_url() {
        global $CFG;
        return new moodle_url('/mod/workshopplus/editform.php', array('cmid' => $this->cm->id));
    }

    /**
     * @return moodle_url of the page for previewing this workshop's grading form
     */
    public function previewform_url() {
        global $CFG;
        return new moodle_url('/mod/workshopplus/editformpreview.php', array('cmid' => $this->cm->id));
    }

    /**
     * @param int $assessmentid The ID of assessment record
     * @return moodle_url of the assessment page
     */
    public function assess_url($assessmentid) {
        global $CFG;
        $assessmentid = clean_param($assessmentid, PARAM_INT);
        return new moodle_url('/mod/workshopplus/assessment.php', array('asid' => $assessmentid));
    }

    /**
     * @param int $assessmentid The ID of assessment record
     * @return moodle_url of the example assessment page
     */
    public function exassess_url($assessmentid) {
        global $CFG;
        $assessmentid = clean_param($assessmentid, PARAM_INT);
        return new moodle_url('/mod/workshopplus/exassessment.php', array('asid' => $assessmentid));
    }

    /**
     * @return moodle_url of the page to view a submission, defaults to the own one
     */
    public function submission_url($id=null) {
        global $CFG;
        return new moodle_url('/mod/workshopplus/submission.php', array('cmid' => $this->cm->id, 'id' => $id));
    }

    /**
     * @param int $id example submission id
     * @return moodle_url of the page to view an example submission
     */
    public function exsubmission_url($id) {
        global $CFG;
        return new moodle_url('/mod/workshopplus/exsubmission.php', array('cmid' => $this->cm->id, 'id' => $id));
    }

    /**
     * @param int $sid submission id
     * @param array $aid of int assessment ids
     * @return moodle_url of the page to compare assessments of the given submission
     */
    public function compare_url($sid, array $aids) {
        global $CFG;

        $url = new moodle_url('/mod/workshopplus/compare.php', array('cmid' => $this->cm->id, 'sid' => $sid));
        $i = 0;
        foreach ($aids as $aid) {
            $url->param("aid{$i}", $aid);
            $i++;
        }
        return $url;
    }

    /**
     * @param int $sid submission id
     * @param int $aid assessment id
     * @return moodle_url of the page to compare the reference assessments of the given example submission
     */
    public function excompare_url($sid, $aid, $exas = false) {
        global $CFG;
        return new moodle_url('/mod/workshopplus/excompare.php', array('cmid' => $this->cm->id, 'sid' => $sid, 'aid' => $aid, 'exas' => $exas));
    }

    /**
     * @return moodle_url of the mod_edit form
     */
    public function updatemod_url() {
        global $CFG;
        return new moodle_url('/course/modedit.php', array('update' => $this->cm->id, 'return' => 1));
    }

    /**
     * @param string $method allocation method
     * @return moodle_url to the allocation page
     */
    public function allocation_url($method=null) {
        global $CFG;
        $params = array('cmid' => $this->cm->id);
        if (!empty($method)) {
            $params['method'] = $method;
        }
        return new moodle_url('/mod/workshopplus/allocation.php', $params);
    }

    /**
     * @param int $phasecode The internal phase code
     * @return moodle_url of the script to change the current phase to $phasecode
     */
    public function switchphase_url($phasecode) {
        global $CFG;
        $phasecode = clean_param($phasecode, PARAM_INT);
        return new moodle_url('/mod/workshopplus/switchphase.php', array('cmid' => $this->cm->id, 'phase' => $phasecode));
    }

    /**
     * @return moodle_url to the aggregation page
     */
    public function aggregate_url() {
        global $CFG;
        return new moodle_url('/mod/workshopplus/aggregate.php', array('cmid' => $this->cm->id));
    }

    /**
     * @return moodle_url of this workshop's toolbox page
     */
    public function toolbox_url($tool) {
        global $CFG;
        return new moodle_url('/mod/workshopplus/toolbox.php', array('id' => $this->cm->id, 'tool' => $tool));
    }

    /**
     * Prepares data object with all workshop grades to be rendered
     *
     * @param int $userid the user we are preparing the report for
     * @param int $groupid if non-zero, prepare the report for the given group only
     * @param int $page the current page (for the pagination)
     * @param int $perpage participants per page (for the pagination)
     * @param string $sortby lastname|firstname|submissiontitle|submissiongrade|gradinggrade
     * @param string $sorthow ASC|DESC
     * @return stdclass data for the renderer
     */
    public function prepare_grading_report_data($userid, $groupid, $page, $perpage, $sortby, $sorthow) {
        global $DB;

        $canviewall     = has_capability('mod/workshop:viewallassessments', $this->context, $userid);
        $isparticipant  = $this->is_participant($userid);

        if (!$canviewall and !$isparticipant) {
            // who the hell is this?
            return array();
        }

        if (!in_array($sortby, array('lastname', 'firstname', 'submissiontitle', 'submissionmodified',
                'submissiongrade', 'gradinggrade'))) {
            $sortby = 'lastname';
        }

        if (!($sorthow === 'ASC' or $sorthow === 'DESC')) {
            $sorthow = 'ASC';
        }

        // get the list of user ids to be displayed
        if ($canviewall) {
            $participants = $this->get_participants(false, $groupid);
        } else {
            // this is an ordinary workshop participant (aka student) - display the report just for him/her
            $participants = array($userid => (object)array('id' => $userid));
        }

        // we will need to know the number of all records later for the pagination purposes
        $numofparticipants = count($participants);

        if ($numofparticipants > 0) {
            // load all fields which can be used for sorting and paginate the records
            list($participantids, $params) = $DB->get_in_or_equal(array_keys($participants), SQL_PARAMS_NAMED);
            $params['workshopid1'] = $this->id;
            $params['workshopid2'] = $this->id;
            $sqlsort = array();
            $sqlsortfields = array($sortby => $sorthow) + array('lastname' => 'ASC', 'firstname' => 'ASC', 'u.id' => 'ASC');
            foreach ($sqlsortfields as $sqlsortfieldname => $sqlsortfieldhow) {
                $sqlsort[] = $sqlsortfieldname . ' ' . $sqlsortfieldhow;
            }
            $sqlsort = implode(',', $sqlsort);
            $picturefields = user_picture::fields('u', array(), 'userid');
            $sql = "SELECT $picturefields, s.title AS submissiontitle, s.timemodified AS submissionmodified,
                           s.grade AS submissiongrade, ag.gradinggrade
                      FROM {user} u
                 LEFT JOIN {workshopplus_submissions} s ON (s.authorid = u.id AND s.workshopid = :workshopid1 AND s.example = 0)
                 LEFT JOIN {workshopplus_aggregations} ag ON (ag.userid = u.id AND ag.workshopid = :workshopid2)
                     WHERE u.id $participantids
                  ORDER BY $sqlsort";
            $participants = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);
        } else {
            $participants = array();
        }

        // this will hold the information needed to display user names and pictures
        $userinfo = array();

        // get the user details for all participants to display
        $additionalnames = get_all_user_name_fields();
        foreach ($participants as $participant) {
            if (!isset($userinfo[$participant->userid])) {
                $userinfo[$participant->userid]            = new stdclass();
                $userinfo[$participant->userid]->id        = $participant->userid;
                $userinfo[$participant->userid]->picture   = $participant->picture;
                $userinfo[$participant->userid]->imagealt  = $participant->imagealt;
                $userinfo[$participant->userid]->email     = $participant->email;
                foreach ($additionalnames as $addname) {
                    $userinfo[$participant->userid]->$addname = $participant->$addname;
                }
            }
        }

        // load the submissions details
        $submissions = $this->get_submissions(array_keys($participants));

        // get the user details for all moderators (teachers) that have overridden a submission grade
        foreach ($submissions as $submission) {
            if (!isset($userinfo[$submission->gradeoverby])) {
                $userinfo[$submission->gradeoverby]            = new stdclass();
                $userinfo[$submission->gradeoverby]->id        = $submission->gradeoverby;
                $userinfo[$submission->gradeoverby]->picture   = $submission->overpicture;
                $userinfo[$submission->gradeoverby]->imagealt  = $submission->overimagealt;
                $userinfo[$submission->gradeoverby]->email     = $submission->overemail;
                foreach ($additionalnames as $addname) {
                    $temp = 'over' . $addname;
                    $userinfo[$submission->gradeoverby]->$addname = $submission->$temp;
                }
            }
        }

        // get the user details for all reviewers of the displayed participants
        $reviewers = array();

        if ($submissions) {
            list($submissionids, $params) = $DB->get_in_or_equal(array_keys($submissions), SQL_PARAMS_NAMED);
            list($sort, $sortparams) = users_order_by_sql('r');
            $picturefields = user_picture::fields('r', array(), 'reviewerid');
            $sql = "SELECT a.id AS assessmentid, a.submissionid, a.grade, a.gradinggrade, a.gradinggradeover, a.weight,
                           $picturefields, s.id AS submissionid, s.authorid
                      FROM {workshopplus_assessments} a
                      JOIN {user} r ON (a.reviewerid = r.id)
                      JOIN {workshopplus_submissions} s ON (a.submissionid = s.id AND s.example = 0)
                     WHERE a.submissionid $submissionids
                  ORDER BY a.weight DESC, $sort";
            $reviewers = $DB->get_records_sql($sql, array_merge($params, $sortparams));
            foreach ($reviewers as $reviewer) {
                if (!isset($userinfo[$reviewer->reviewerid])) {
                    $userinfo[$reviewer->reviewerid]            = new stdclass();
                    $userinfo[$reviewer->reviewerid]->id        = $reviewer->reviewerid;
                    $userinfo[$reviewer->reviewerid]->picture   = $reviewer->picture;
                    $userinfo[$reviewer->reviewerid]->imagealt  = $reviewer->imagealt;
                    $userinfo[$reviewer->reviewerid]->email     = $reviewer->email;
                    foreach ($additionalnames as $addname) {
                        $userinfo[$reviewer->reviewerid]->$addname = $reviewer->$addname;
                    }
                }
            }
        }

        // get the user details for all reviewees of the displayed participants
        $reviewees = array();
        if ($participants) {
            list($participantids, $params) = $DB->get_in_or_equal(array_keys($participants), SQL_PARAMS_NAMED);
            list($sort, $sortparams) = users_order_by_sql('e');
            $params['workshopid'] = $this->id;
            $picturefields = user_picture::fields('e', array(), 'authorid');
            $sql = "SELECT a.id AS assessmentid, a.submissionid, a.grade, a.gradinggrade, a.gradinggradeover, a.reviewerid, a.weight,
                           s.id AS submissionid, $picturefields
                      FROM {user} u
                      JOIN {workshopplus_assessments} a ON (a.reviewerid = u.id)
                      JOIN {workshopplus_submissions} s ON (a.submissionid = s.id AND s.example = 0)
                      JOIN {user} e ON (s.authorid = e.id)
                     WHERE u.id $participantids AND s.workshopid = :workshopid
                  ORDER BY a.weight DESC, $sort";
            $reviewees = $DB->get_records_sql($sql, array_merge($params, $sortparams));
            foreach ($reviewees as $reviewee) {
                if (!isset($userinfo[$reviewee->authorid])) {
                    $userinfo[$reviewee->authorid]            = new stdclass();
                    $userinfo[$reviewee->authorid]->id        = $reviewee->authorid;
                    $userinfo[$reviewee->authorid]->picture   = $reviewee->picture;
                    $userinfo[$reviewee->authorid]->imagealt  = $reviewee->imagealt;
                    $userinfo[$reviewee->authorid]->email     = $reviewee->email;
                    foreach ($additionalnames as $addname) {
                        $userinfo[$reviewee->authorid]->$addname = $reviewee->$addname;
                    }
                }
            }
        }

        // finally populate the object to be rendered
        $grades = $participants;

        foreach ($participants as $participant) {
            // set up default (null) values
            $grades[$participant->userid]->submissionid = null;
            $grades[$participant->userid]->submissiontitle = null;
            $grades[$participant->userid]->submissiongrade = null;
            $grades[$participant->userid]->submissiongradeover = null;
            $grades[$participant->userid]->submissiongradeoverby = null;
            $grades[$participant->userid]->submissionpublished = null;
            $grades[$participant->userid]->reviewedby = array();
            $grades[$participant->userid]->reviewerof = array();
        }
        unset($participants);
        unset($participant);

        foreach ($submissions as $submission) {
            $grades[$submission->authorid]->submissionid = $submission->id;
            $grades[$submission->authorid]->submissiontitle = $submission->title;
            $grades[$submission->authorid]->submissiongrade = $this->real_grade($submission->grade);
            $grades[$submission->authorid]->submissiongradeover = $this->real_grade($submission->gradeover);
            $grades[$submission->authorid]->submissiongradeoverby = $submission->gradeoverby;
            $grades[$submission->authorid]->submissionpublished = $submission->published;
        }
        unset($submissions);
        unset($submission);

        foreach ($reviewers as $reviewer) {
            $info = new stdclass();
            $info->userid = $reviewer->reviewerid;
            $info->assessmentid = $reviewer->assessmentid;
            $info->submissionid = $reviewer->submissionid;
            $info->grade = $this->real_grade($reviewer->grade);
            $info->gradinggrade = $this->real_grading_grade($reviewer->gradinggrade);
            $info->gradinggradeover = $this->real_grading_grade($reviewer->gradinggradeover);
            $info->weight = $reviewer->weight;
            $grades[$reviewer->authorid]->reviewedby[$reviewer->reviewerid] = $info;
        }
        unset($reviewers);
        unset($reviewer);

        foreach ($reviewees as $reviewee) {
            $info = new stdclass();
            $info->userid = $reviewee->authorid;
            $info->assessmentid = $reviewee->assessmentid;
            $info->submissionid = $reviewee->submissionid;
            $info->grade = $this->real_grade($reviewee->grade);
            $info->gradinggrade = $this->real_grading_grade($reviewee->gradinggrade);
            $info->gradinggradeover = $this->real_grading_grade($reviewee->gradinggradeover);
            $info->weight = $reviewee->weight;
            $grades[$reviewee->reviewerid]->reviewerof[$reviewee->authorid] = $info;
        }
        unset($reviewees);
        unset($reviewee);

        foreach ($grades as $grade) {
            $grade->gradinggrade = $this->real_grading_grade($grade->gradinggrade);
        }

        $data = new stdclass();
        $data->grades = $grades;
        $data->userinfo = $userinfo;
        $data->totalcount = $numofparticipants;
        $data->maxgrade = $this->real_grade(100);
        $data->maxgradinggrade = $this->real_grading_grade(100);
        return $data;
    }

    /**
     * Calculates grades for submission for the given participant(s) and updates it in the database
     *
     * @param null|int|array $restrict If null, update all authors, otherwise update just grades for the given author(s)
     * @return void
     */
    public function aggregate_submission_grades($restrict=null) {
        global $DB;

        // fetch a recordset with all assessments to process
        $sql = 'SELECT s.id AS submissionid, s.grade AS submissiongrade,
                       a.weight, a.grade
                  FROM {workshopplus_submissions} s
             LEFT JOIN {workshopplus_assessments} a ON (a.submissionid = s.id)
                 WHERE s.example=0 AND s.workshopid=:workshopid'; // to be cont.
        $params = array('workshopid' => $this->id);

        if (is_null($restrict)) {
            echo("TODO: fix empty if");
            // update all users - no more conditions
        } else if (!empty($restrict)) {
            list($usql, $uparams) = $DB->get_in_or_equal($restrict, SQL_PARAMS_NAMED);
            $sql .= " AND s.authorid $usql";
            $params = array_merge($params, $uparams);
        } else {
            throw new coding_exception('Empty value is not a valid parameter here');
        }

        $sql .= ' ORDER BY s.id'; // this is important for bulk processing

        $rs         = $DB->get_recordset_sql($sql, $params);
        $batch      = array();    // will contain a set of all assessments of a single submission
        $previous   = null;       // a previous record in the recordset

        foreach ($rs as $current) {
            if (is_null($previous)) {
                // we are processing the very first record in the recordset
                $previous   = $current;
            }
            if ($current->submissionid == $previous->submissionid) {
                // we are still processing the current submission
                $batch[] = $current;
            } else {
                // process all the assessments of a sigle submission
                $this->aggregate_submission_grades_process($batch);
                // and then start to process another submission
                $batch      = array($current);
                $previous   = $current;
            }
        }
        // do not forget to process the last batch!
        $this->aggregate_submission_grades_process($batch);
        $rs->close();
    }

    /**
     * Calculates grades for assessment for the given participant(s)
     *
     * Grade for assessment is calculated as a simple mean of all grading grades calculated by the grading evaluator.
     * The assessment weight is not taken into account here.
     *
     * @param null|int|array $restrict If null, update all reviewers, otherwise update just grades for the given reviewer(s)
     * @return void
     */
    public function aggregate_grading_grades($restrict=null) {
        global $DB;

        // fetch a recordset with all assessments to process
        $sql = 'SELECT a.reviewerid, a.gradinggrade, a.gradinggradeover,
                       ag.id AS aggregationid, ag.gradinggrade AS aggregatedgrade
                  FROM {workshopplus_assessments} a
            INNER JOIN {workshopplus_submissions} s ON (a.submissionid = s.id)
             LEFT JOIN {workshopplus_aggregations} ag ON (ag.userid = a.reviewerid AND ag.workshopid = s.workshopid)
                 WHERE s.example=0 AND s.workshopid=:workshopid'; // to be cont.
        $params = array('workshopid' => $this->id);

        if (is_null($restrict)) {
            echo("TODO: fix empty if");
            // update all users - no more conditions
        } else if (!empty($restrict)) {
            list($usql, $uparams) = $DB->get_in_or_equal($restrict, SQL_PARAMS_NAMED);
            $sql .= " AND a.reviewerid $usql";
            $params = array_merge($params, $uparams);
        } else {
            throw new coding_exception('Empty value is not a valid parameter here');
        }

        $sql .= ' ORDER BY a.reviewerid'; // this is important for bulk processing

        $rs         = $DB->get_recordset_sql($sql, $params);
        $batch      = array();    // will contain a set of all assessments of a single submission
        $previous   = null;       // a previous record in the recordset

        foreach ($rs as $current) {
            if (is_null($previous)) {
                // we are processing the very first record in the recordset
                $previous   = $current;
            }
            if ($current->reviewerid == $previous->reviewerid) {
                // we are still processing the current reviewer
                $batch[] = $current;
            } else {
                // process all the assessments of a sigle submission
                $this->aggregate_grading_grades_process($batch);
                // and then start to process another reviewer
                $batch      = array($current);
                $previous   = $current;
            }
        }
        // do not forget to process the last batch!
        $this->aggregate_grading_grades_process($batch);
        $rs->close();
    }

    /**
     * Returns SQL to fetch all enrolled users with the given capability in the current workshop
     *
     * The returned array consists of string $sql and the $params array. Note that the $sql can be
     * empty if a grouping is selected and it has no groups.
     *
     * The list is automatically restricted according to any availability restrictions
     * that apply to user lists (e.g. group, grouping restrictions).
     *
     * @param string $capability the name of the capability
     * @param bool $musthavesubmission ff true, return only users who have already submitted
     * @param int $groupid 0 means ignore groups, any other value limits the result by group id
     * @return array of (string)sql, (array)params
     */
    protected function get_users_with_capability_sql($capability, $musthavesubmission, $groupid) {
        global $CFG;
        /** @var int static counter used to generate unique parameter holders */
        static $inc = 0;
        $inc++;

        // If the caller requests all groups and we are using a selected grouping,
        // recursively call this function for each group in the grouping (this is
        // needed because get_enrolled_sql only supports a single group).
        if (empty($groupid) and $this->cm->groupingid) {
            $groupingid = $this->cm->groupingid;
            $groupinggroupids = array_keys(groups_get_all_groups($this->cm->course, 0, $this->cm->groupingid, 'g.id'));
            $sql = array();
            $params = array();
            foreach ($groupinggroupids as $groupinggroupid) {
                if ($groupinggroupid > 0) { // just in case in order not to fall into the endless loop
                    list($gsql, $gparams) = $this->get_users_with_capability_sql($capability, $musthavesubmission, $groupinggroupid);
                    $sql[] = $gsql;
                    $params = array_merge($params, $gparams);
                }
            }
            $sql = implode(PHP_EOL." UNION ".PHP_EOL, $sql);
            return array($sql, $params);
        }

        list($esql, $params) = get_enrolled_sql($this->context, $capability, $groupid, true);

        $userfields = user_picture::fields('u');

        $sql = "SELECT $userfields
                  FROM {user} u
                  JOIN ($esql) je ON (je.id = u.id AND u.deleted = 0) ";

        if ($musthavesubmission) {
            $sql .= " JOIN {workshopplus_submissions} ws ON (ws.authorid = u.id AND ws.example = 0 AND ws.workshopid = :workshopid{$inc}) ";
            $params['workshopid'.$inc] = $this->id;
        }

        // If the activity is restricted so that only certain users should appear
        // in user lists, integrate this into the same SQL.
        $info = new \core_availability\info_module($this->cm);
        list ($listsql, $listparams) = $info->get_user_list_sql(false);
        if ($listsql) {
            $sql .= " JOIN ($listsql) restricted ON restricted.id = u.id ";
            $params = array_merge($params, $listparams);
        }

        return array($sql, $params);
    }

    /**
     * Removes all user data related to assessments (including allocations).
     *
     * This includes assessments of example submissions as long as they are not
     * referential assessments.
     *
     * @param stdClass $data The actual course reset settings.
     * @return bool|string True on success, error message otherwise.
     */
    protected function reset_userdata_assessments(stdClass $data) {
        global $DB;

        $sql = "SELECT a.id
                  FROM {workshopplus_assessments} a
                  JOIN {workshopplus_submissions} s ON (a.submissionid = s.id)
                 WHERE s.workshopid = :workshopid
                       AND (s.example = 0 OR (s.example = 1 AND a.weight = 0))";

        $assessments = $DB->get_records_sql($sql, array('workshopid' => $this->id));
        $this->delete_assessment(array_keys($assessments));

        $DB->delete_records('workshopplus_aggregations', array('workshopid' => $this->id));

        return true;
    }

}
