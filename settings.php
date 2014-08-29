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
 * VOOT enrolment plugin settings and presets.
 *
 * @package    enrol_voot
 * @copyright  2014 Andrea Biancini
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    //--- general settings -----------------------------------------------------------------------------------
    $settings->add(new admin_setting_heading('enrol_voot_settings', '', get_string('pluginname_desc', 'enrol_voot')));

    $settings->add(new admin_setting_heading('enrol_voot_exvootheader', get_string('settingsheadervoot', 'enrol_voot'), ''));

    $options = array('http'=>'http', 'https'=>'https');
    $settings->add(new admin_setting_configselect('enrol_voot/vootproto', get_string('vootproto', 'enrol_voot'), '', 'https', $options));

    $settings->add(new admin_setting_configtext('enrol_voot/voothost', get_string('voothost', 'enrol_voot'), get_string('voothost_desc', 'enrol_voot'), 'localhost'));

    $settings->add(new admin_setting_configtext('enrol_voot/vootuser', get_string('vootuser', 'enrol_voot'), '', 'GrouperSystem'));

    $settings->add(new admin_setting_configpasswordunmask('enrol_voot/vootpass', get_string('vootpass', 'enrol_voot'), '', ''));

    $settings->add(new admin_setting_configtext('enrol_voot/urlprefix', get_string('urlprefix', 'enrol_voot'), get_string('urlprefix_desc', 'enrol_voot'), '/grouper-ws/voot'));

    $settings->add(new admin_setting_configtext('enrol_voot/localcoursefield', get_string('localcoursefield', 'enrol_voot'), get_string('localcoursefield_desc', 'enrol_voot'), '0'));

    $settings->add(new admin_setting_configtext('enrol_voot/groupprefix', get_string('groupprefix', 'enrol_voot'), get_string('groupprefix_desc', 'enrol_voot'), ''));

    $settings->add(new admin_setting_configtext('enrol_voot/localuserfield', get_string('localuserfield', 'enrol_voot'), get_string('localuserfield_desc', 'enrol_voot'), 'admins'));

    if (!during_initial_install()) {
        $options = get_default_enrol_roles(context_system::instance());
        $student = get_archetype_roles('student');
        $student = reset($student);
        $settings->add(new admin_setting_configselect('enrol_voot/defaultrole', get_string('defaultrole', 'enrol_voot'), get_string('defaultrole_desc', 'enrol_voot'), $student->id, $options));
    }

    $options = array(ENROL_EXT_REMOVED_UNENROL        => get_string('extremovedunenrol', 'enrol'),
                     ENROL_EXT_REMOVED_KEEP           => get_string('extremovedkeep', 'enrol'),
                     ENROL_EXT_REMOVED_SUSPEND        => get_string('extremovedsuspend', 'enrol'),
                     ENROL_EXT_REMOVED_SUSPENDNOROLES => get_string('extremovedsuspendnoroles', 'enrol'));
    $settings->add(new admin_setting_configselect('enrol_voot/unenrolaction', get_string('extremovedaction', 'enrol'), get_string('extremovedaction_help', 'enrol'), ENROL_EXT_REMOVED_UNENROL, $options));

    $settings->add(new admin_setting_configtext('enrol_voot/newcoursefullname', get_string('newcoursefullname', 'enrol_voot'), '', 'name'));

    $settings->add(new admin_setting_configtext('enrol_voot/newcourseshortname', get_string('newcourseshortname', 'enrol_voot'), '', 'id'));

    $options = array('id'=>'id', 'idnumber'=>'idnumber');
    $settings->add(new admin_setting_configselect('enrol_voot/localcategoryfield', get_string('localcategoryfield', 'enrol_voot'), '', 'id', $options));

    if (!during_initial_install()) {
        $settings->add(new admin_setting_configselect('enrol_voot/defaultcategory', get_string('defaultcategory', 'enrol_voot'), get_string('defaultcategory_desc', 'enrol_voot'), 1, make_categories_options()));
    }

    $settings->add(new admin_setting_configtext('enrol_voot/templatecourse', get_string('templatecourse', 'enrol_voot'), get_string('templatecourse_desc', 'enrol_voot'), ''));
}
