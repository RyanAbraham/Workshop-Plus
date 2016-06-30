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
 * View, create or edit single example submission
 *
 * @package    mod_workshopplus
 * @copyright  2009 David Mudrak <david.mudrak@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once(dirname(__FILE__).'/locallib.php');
require_once($CFG->dirroot . '/repository/lib.php');

$cmid       = required_param('cmid', PARAM_INT);            // course module id
$id         = required_param('id', PARAM_INT);              // example submission id, 0 for the new one
$edit       = optional_param('edit', false, PARAM_BOOL);    // open for editing?
$delete     = optional_param('delete', false, PARAM_BOOL);  // example removal requested
$confirm    = optional_param('confirm', false, PARAM_BOOL); // example removal request confirmed
$assess     = optional_param('assess', false, PARAM_BOOL);  // assessment required

$cm         = get_coursemodule_from_id('workshopplus', $cmid, 0, false, MUST_EXIST);
$course     = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);

require_login($course, false, $cm);
if (isguestuser()) {
    print_error('guestsarenotallowed');
}

$workshop = $DB->get_record('workshopplus', array('id' => $cm->instance), '*', MUST_EXIST);
$workshop = new workshopplus($workshop, $cm, $course);

$PAGE->set_url($workshop->exsubmission_url($id), array('edit' => $edit));
$PAGE->set_title($workshop->name);
$PAGE->set_heading($course->fullname);
if ($edit) {
    $PAGE->navbar->add(get_string('exampleediting', 'workshopplus'));
} else {
    $PAGE->navbar->add(get_string('example', 'workshopplus'));
}
$output = $PAGE->get_renderer('mod_workshopplus');

if ($id) { // example is specified
    $example = $workshop->get_example_by_id($id);
} else { // no example specified - create new one
    require_capability('mod/workshop:manageexamples', $workshop->context);
    $example = new stdclass();
    $example->id = null;
    $example->authorid = $USER->id;
    $example->example = 1;
}

$canmanage  = has_capability('mod/workshop:manageexamples', $workshop->context);
$canassess  = has_capability('mod/workshop:peerassess', $workshop->context);
$refasid    = $DB->get_field('workshopplus_assessments', 'id', array('submissionid' => $example->id, 'weight' => 1));

if ($example->id and ($canmanage or ($workshop->assessing_examples_allowed() and $canassess))) {
    echo("TODO: fix empty if");
    // ok you can go
} else if (is_null($example->id) and $canmanage) {
    echo("TODO: fix empty if");
    // ok you can go
} else {
    print_error('nopermissions', 'error', $workshop->view_url(), 'view or manage example submission');
}

if ($id and $delete and $confirm and $canmanage) {
    require_sesskey();
    $workshop->delete_submission($example);
    redirect($workshop->view_url());
}

if ($id and $assess and $canmanage) {
    // reference assessment of an example is the assessment with the weight = 1. There should be just one
    // such assessment
    require_sesskey();
    if (!$refasid) {
        $refasid = $workshop->add_allocation($example, $USER->id, 1);
    }
    redirect($workshop->exassess_url($refasid));
}

if ($id and $assess and $canassess) {
    // training assessment of an example is the assessment with the weight = 0
    require_sesskey();
    $asid = $DB->get_field('workshopplus_assessments', 'id',
            array('submissionid' => $example->id, 'weight' => 0, 'reviewerid' => $USER->id));
    if (!$asid) {
        $scamaz = undefined_global; // Creates an error so that the debug message appears
        echo "achieved<br>";
        $asid = $workshop->add_allocation($example, $USER->id, 0);
        echo "<h1>asID Returned: <font color='orange'>$asid</font></h1>";
    }
    if ($asid == workshop::ALLOCATION_EXISTS) {
        // the training assessment of the example was not found but the allocation already
        // exists. this probably means that the user is the author of the reference assessment.
        echo $output->header();
        echo $output->box(get_string('assessmentreferenceconflict', 'workshopplus'));
        echo $output->continue_button($workshop->view_url());
        echo $output->footer();
        die();
    }
    redirect($workshop->exassess_url($asid));
}

if ($edit and $canmanage) {
    require_once(dirname(__FILE__).'/submission_form.php');

    $example = file_prepare_standard_editor($example, 'content', $workshop->submission_content_options(),
        $workshop->context, 'mod_workshopplus', 'submission_content', $example->id);

    $example = file_prepare_standard_filemanager($example, 'attachment', $workshop->submission_attachment_options(),
        $workshop->context, 'mod_workshopplus', 'submission_attachment', $example->id);

    $mform = new workshopplus_submission_form($PAGE->url, array('current' => $example, 'workshopplus' => $workshop,
        'contentopts' => $workshop->submission_content_options(), 'attachmentopts' => $workshop->submission_attachment_options()));

    if ($mform->is_cancelled()) {
        redirect($workshop->view_url());

    } else if ($canmanage and $formdata = $mform->get_data()) {
        if ($formdata->example == 1) {
            // this was used just for validation, it must be set to one when dealing with example submissions
            unset($formdata->example);
        } else {
            throw new coding_exception('Invalid submission form data value: example');
        }
        $timenow = time();
        if (is_null($example->id)) {
            $formdata->workshopid     = $workshop->id;
            $formdata->example        = 1;
            $formdata->authorid       = $USER->id;
            $formdata->timecreated    = $timenow;
            $formdata->feedbackauthorformat = editors_get_preferred_format();
        }
        $formdata->timemodified       = $timenow;
        $formdata->title              = trim($formdata->title);
        $formdata->content            = '';          // updated later
        $formdata->contentformat      = FORMAT_HTML; // updated later
        $formdata->contenttrust       = 0;           // updated later
        if (is_null($example->id)) {
            $example->id = $formdata->id = $DB->insert_record('workshopplus_submissions', $formdata);
        } else {
            if (empty($formdata->id) or empty($example->id) or ($formdata->id != $example->id)) {
                throw new moodle_exception('err_examplesubmissionid', 'workshopplus');
            }
        }
        // save and relink embedded images and save attachments
        $formdata = file_postupdate_standard_editor($formdata, 'content', $workshop->submission_content_options(),
                                        $workshop->context, 'mod_workshopplus', 'submission_content', $example->id);
        $formdata = file_postupdate_standard_filemanager($formdata, 'attachment', $workshop->submission_attachment_options(),
                                        $workshop->context, 'mod_workshopplus', 'submission_attachment', $example->id);
        if (empty($formdata->attachment)) {
            // explicit cast to zero integer
            $formdata->attachment = 0;
        }
        // store the updated values or re-save the new example (re-saving needed because URLs are now rewritten)
        $DB->update_record('workshopplus_submissions', $formdata);
        redirect($workshop->exsubmission_url($formdata->id));
    }
}

// Output starts here
echo $output->header();
echo $output->heading(format_string($workshop->name), 2);

// show instructions for submitting as they may contain some list of questions and we need to know them
// while reading the submitted answer
if (trim($workshop->instructauthors)) {
    $instructions = file_rewrite_pluginfile_urls($workshop->instructauthors, 'pluginfile.php', $PAGE->context->id,
        'mod_workshopplus', 'instructauthors', 0, workshop::instruction_editors_options($PAGE->context));
    print_collapsible_region_start('', 'workshop-viewlet-instructauthors', get_string('instructauthors', 'workshopplus'));
    echo $output->box(format_text($instructions, $workshop->instructauthorsformat, array('overflowdiv' => true)), array('generalbox', 'instructions'));
    print_collapsible_region_end();
}

// if in edit mode, display the form to edit the example
if ($edit and $canmanage) {
    $mform->display();
    echo $output->footer();
    die();
}

// else display the example...
if ($example->id) {
    if ($canmanage and $delete) {
        echo $output->confirm(get_string('exampledeleteconfirm', 'workshopplus'),
        new moodle_url($PAGE->url, array('delete' => 1, 'confirm' => 1)), $workshop->view_url());
    }
    if ($canmanage and !$delete and !$DB->record_exists_select('workshopplus_assessments',
            'grade IS NOT NULL AND weight=1 AND submissionid = ?', array($example->id))) {
        echo $output->confirm(get_string('assessmentreferenceneeded', 'workshopplus'),
                new moodle_url($PAGE->url, array('assess' => 1)), $workshop->view_url());
    }
    echo $output->render($workshop->prepare_example_submission($example));
}
// ...with an option to edit or remove it
echo $output->container_start('buttonsbar');
if ($canmanage) {
    if (empty($edit) and empty($delete)) {
        $aurl = new moodle_url($workshop->exsubmission_url($example->id), array('edit' => 'on'));
        echo $output->single_button($aurl, get_string('exampleedit', 'workshopplus'), 'get');

        $aurl = new moodle_url($workshop->exsubmission_url($example->id), array('delete' => 'on'));
        echo $output->single_button($aurl, get_string('exampledelete', 'workshopplus'), 'get');
    }
}
// ...and optionally assess it
if ($canassess or ($canmanage and empty($edit) and empty($delete))) {
    $aurl = new moodle_url($workshop->exsubmission_url($example->id), array('assess' => 'on', 'sesskey' => sesskey()));
    echo $output->single_button($aurl, get_string('exampleassess', 'workshopplus'), 'get');
}
echo $output->container_end(); // buttonsbar
// and possibly display the example's review(s) - todo
echo $output->footer();
