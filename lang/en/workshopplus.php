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
 * Strings for component 'workshop', language 'en', branch 'MOODLE_20_STABLE'
 *
 * @package    mod_workshopplus
 * @copyright  2009 David Mudrak <david@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// Extending workshop's english language file.
require_once(dirname(dirname(dirname(dirname(__FILE__)))).'/workshop/lang/en/workshop.php');

$string['modulename'] = 'Workshop Plus';
$string['modulenameplural'] = 'Workshop+s';
$string['modulename_help'] = 'The workshop plus activity module enables the collection, review and peer assessment of students\' work.

Students can submit any digital content (files), such as word-processed documents or spreadsheets and can also type text directly into a field using the text editor.

Submissions are assessed using a multi-criteria assessment form defined by the teacher. The process of peer assessment and understanding the assessment form can be practised in advance with example submissions provided by the teacher, together with a reference assessment. Students are given the opportunity to assess one or more of their peers\' submissions. Submissions and reviewers may be anonymous if required.

Students obtain two grades in a workshop activity - a grade for their submission and a grade for their assessment of their peers\' submissions. Both grades are recorded in the gradebook.';
$string['modulename_link'] = 'mod/workshopplus/view';
$string['useexamples_help'] = 'If enabled, users will assess one or more example submissions. Their evaluation is compared
	to the teacher\'s evaluation. A quality score will be calculated and assigned to them based on how close their evaluation
	is to the teacher\'s.

	The Workshop+ plugin is built around this system, so it must be enabled for Workshop+ to function properly.';