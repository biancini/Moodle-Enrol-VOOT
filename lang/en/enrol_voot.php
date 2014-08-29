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
 * Strings for component 'enrol_voot', language 'en'.
 *
 * @package   enrol_voot
 * @copyright 2014 onwards Andrea Biancini
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'VOOT Server';
$string['pluginname_desc'] = 'You can use an external VOOT server to control your enrolments. It is assumed your external VOOT contains at least a field containing a course ID, and a field containing a user ID. These are compared against fields that you choose in the local course and user tables.';
$string['settingsheadervoot'] = 'External VOOT server connection';
$string['voot:unenrol'] = 'Unenrol suspended users';
$string['vootproto'] = 'VOOT host protocol';
$string['voothost'] = 'VOOT host';
$string['voothost_desc'] = 'Type VOOT server IP address or host name.';
$string['vootuser'] = 'VOOT user name';
$string['vootpass'] = 'VOOT password';
$string['urlprefix'] = 'URL prefix';
$string['urlprefix_desc'] = 'URL prefix for VOOT interface.';
$string['localcoursefield'] = 'Local course field';
$string['localcoursefield_desc'] = 'The VOOT course name may be splitted on \':\', this field permits to indicate the index of the resulting array to be used as course name field';
$string['groupprefix'] = 'Group prefix for courses';
$string['groupprefix_desc'] = 'The prefix of the group name form VOOT that specifies groups to be used as courses';
$string['localuserfield'] = 'Group name for teachers';
$string['localuserfield_desc'] = 'Specify the gruoup name to be searched to identify a teacher form a student';
$string['defaultrole'] = 'Default role';
$string['defaultrole_desc'] = 'The role that will be assigned by default if no other role is specified in external table.';
$string['newcoursefullname'] = 'New course full name field';
$string['newcourseshortname'] = 'New course short name field';
$string['localcategoryfield'] = 'Local category field';
$string['defaultcategory'] = 'Default new course category';
$string['defaultcategory_desc'] = 'The default category for auto-created courses. Used when no new category id specified or not found.';
$string['templatecourse'] = 'New course template';
$string['templatecourse_desc'] = 'Optional: auto-created courses can copy their settings from a template course. Type here the shortname of the template course.';

