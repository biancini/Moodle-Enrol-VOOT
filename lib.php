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
 * VOOT enrolment plugin.
 *
 * This plugin synchronises enrolment and roles with external voot table.
 *
 * @package    enrol_voot
 * @copyright  2014 Andrea Biancini
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * VOOT enrolment plugin implementation.
 * @author  Petr Skoda - based on code by Martin Dougiamas, Martin Langhoff and others
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_voot_plugin extends enrol_plugin {
    /**
     * Is it possible to delete enrol instance via standard UI?
     *
     * @param stdClass $instance
     * @return bool
     */
    public function instance_deleteable($instance) {
        if (!enrol_is_enabled('voot')) {
            return true;
        }

        //TODO: connect to external system and make sure no users are to be enrolled in this course
        return false;
    }

    /**
     * Does this plugin allow manual unenrolment of a specific user?
     * Yes, but only if user suspended...
     *
     * @param stdClass $instance course enrol instance
     * @param stdClass $ue record from user_enrolments table
     *
     * @return bool - true means user with 'enrol/xxx:unenrol' may unenrol this user, false means nobody may touch this user enrolment
     */
    public function allow_unenrol_user(stdClass $instance, stdClass $ue) {
        if ($ue->status == ENROL_USER_SUSPENDED) {
            return true;
        }

        return false;
    }

    /**
     * Gets an array of the user enrolment actions.
     *
     * @param course_enrolment_manager $manager
     * @param stdClass $ue A user enrolment object
     * @return array An array of user_enrolment_actions
     */
    public function get_user_enrolment_actions(course_enrolment_manager $manager, $ue) {
        $actions = array();
        $context = $manager->get_context();
        $instance = $ue->enrolmentinstance;
        $params = $manager->get_moodlepage()->url->params();
        $params['ue'] = $ue->id;
        if ($this->allow_unenrol_user($instance, $ue) && has_capability('enrol/voot:unenrol', $context)) {
            $url = new moodle_url('/enrol/unenroluser.php', $params);
            $actions[] = new user_enrolment_action(new pix_icon('t/delete', ''), get_string('unenrol', 'enrol'), $url, array('class'=>'unenrollink', 'rel'=>$ue->id));
        }
        return $actions;
    }

    /**
     * Forces synchronisation of user enrolments with external voot,
     * does not create new courses.
     *
     * @param stdClass $user user record
     * @return void
     */
    public function sync_user_enrolments($user) {
        global $CFG, $DB;

        // We do not create courses here intentionally because it requires full sync and is slow.
        if (!$this->get_config('voothost') or !$this->get_config('urlprefix')) {
            return;
        }

	$localuserfield   = $this->get_config('localuserfield', 'admins');

        $unenrolaction    = $this->get_config('unenrolaction');
        $defaultrole      = $this->get_config('defaultrole');

        $ignorehidden     = $this->get_config('ignorehiddencourses');

        if (!is_object($user) or !property_exists($user, 'id')) {
            throw new coding_exception('Invalid $user parameter in sync_user_enrolments()');
        }

        // Create roles mapping.
        $allroles = get_all_roles();
        if (!isset($allroles[$defaultrole])) {
            $defaultrole = 0;
        }
        $roles = array();
        foreach ($allroles as $role) {
            $roles[$role->$localrolefield] = $role->id;
        }

        $enrols = array();
        $instances = array();

        if (!$extdb = $this->db_init()) {
            // Can not connect to voot, sorry.
            return;
        }

        // Read remote enrols and create instances.
	if (!$enrolments = $this->voot_getenrolments($user->username)) {
		debugging('Error while communicating with external enrolment VOOT server');
		return;
	}

	foreach($enrolments as $curenrolment) {
		if (empty($curenrolment['id'])) {
			// Missing course info.
			continue;
		}
		if (!$course = $DB->get_record('course', array('shortname'=>$curenrolment['id']), 'id,visible')) {
			continue;
		}
		if (!$course->visible and $ignorehidden) {
			continue;
		}

                if (empty($curenrolment['voot_membership_role']) or !isset($curenrolment['voot_membership_role'])) {
                    if (!$defaultrole) {
                        // Role is mandatory.
                        continue;
                    }
                    $roleid = $defaultrole;
                } else {
                    $role = ($curenrolment["voot_membership_role"] == $localuserfield) ? "teacher" : "student";
                    $roleid = $roles[$role];
                }

                if (empty($enrols[$course->id])) {
                    $enrols[$course->id] = array();
                }
                $enrols[$course->id][] = $roleid;

                if ($instance = $DB->get_record('enrol', array('courseid'=>$course->id, 'enrol'=>'voot'), '*', IGNORE_MULTIPLE)) {
                    $instances[$course->id] = $instance;
                    continue;
                }

                $enrolid = $this->add_instance($course);
                $instances[$course->id] = $DB->get_record('enrol', array('id'=>$enrolid));
        }

        // Enrol user into courses and sync roles.
        foreach ($enrols as $courseid => $roles) {
            if (!isset($instances[$courseid])) {
                // Ignored.
                continue;
            }
            $instance = $instances[$courseid];

            if ($e = $DB->get_record('user_enrolments', array('userid'=>$user->id, 'enrolid'=>$instance->id))) {
                // Reenable enrolment when previously disable enrolment refreshed.
                if ($e->status == ENROL_USER_SUSPENDED) {
                    $this->update_user_enrol($instance, $user->id, ENROL_USER_ACTIVE);
                }
            } else {
                $roleid = reset($roles);
                $this->enrol_user($instance, $user->id, $roleid, 0, 0, ENROL_USER_ACTIVE);
            }

            if (!$context = context_course::instance($instance->courseid, IGNORE_MISSING)) {
                // Weird.
                continue;
            }
            $current = $DB->get_records('role_assignments', array('contextid'=>$context->id, 'userid'=>$user->id, 'component'=>'enrol_voot', 'itemid'=>$instance->id), '', 'id, roleid');

            $existing = array();
            foreach ($current as $r) {
                if (in_array($r->roleid, $roles)) {
                    $existing[$r->roleid] = $r->roleid;
                } else {
                    role_unassign($r->roleid, $user->id, $context->id, 'enrol_voot', $instance->id);
                }
            }
            foreach ($roles as $rid) {
                if (!isset($existing[$rid])) {
                    role_assign($rid, $user->id, $context->id, 'enrol_voot', $instance->id);
                }
            }
        }

        // Unenrol as necessary.
        $sql = "SELECT e.*, c.visible AS cvisible, ue.status AS ustatus
                  FROM {enrol} e
                  JOIN {user_enrolments} ue ON ue.enrolid = e.id
                  JOIN {course} c ON c.id = e.courseid
                 WHERE ue.userid = :userid AND e.enrol = 'voot'";
        $rs = $DB->get_recordset_sql($sql, array('userid'=>$user->id));
        foreach ($rs as $instance) {
            if (!$instance->cvisible and $ignorehidden) {
                continue;
            }

            if (!$context = context_course::instance($instance->courseid, IGNORE_MISSING)) {
                // Very weird.
                continue;
            }

            if (!empty($enrols[$instance->courseid])) {
                // We want this user enrolled.
                continue;
            }

            // Deal with enrolments removed from external table
            if ($unenrolaction == ENROL_EXT_REMOVED_UNENROL) {
                $this->unenrol_user($instance, $user->id);

            } else if ($unenrolaction == ENROL_EXT_REMOVED_KEEP) {
                // Keep - only adding enrolments.

            } else if ($unenrolaction == ENROL_EXT_REMOVED_SUSPEND or $unenrolaction == ENROL_EXT_REMOVED_SUSPENDNOROLES) {
                // Suspend users.
                if ($instance->ustatus != ENROL_USER_SUSPENDED) {
                    $this->update_user_enrol($instance, $user->id, ENROL_USER_SUSPENDED);
                }
                if ($unenrolaction == ENROL_EXT_REMOVED_SUSPENDNOROLES) {
                    role_unassign_all(array('contextid'=>$context->id, 'userid'=>$user->id, 'component'=>'enrol_voot', 'itemid'=>$instance->id));
                }
            }
        }
        $rs->close();
    }

    /**
     * Forces synchronisation of all enrolments with external VOOT server.
     *
     * @param progress_trace $trace
     * @param null|int $onecourse limit sync to one course only (used primarily in restore)
     * @return int 0 means success, 1 db connect failure, 2 db read failure
     */
    public function sync_enrolments(progress_trace $trace, $onecourse = null) {
        global $CFG, $DB;

        // We do not create courses here intentionally because it requires full sync and is slow.
        if (!$this->get_config('voothost') or !$this->get_config('urlprefix')) {
            $trace->output('User enrolment synchronisation skipped.');
            $trace->finished();
            return 0;
        }

        $trace->output('Starting user enrolment synchronisation...');

        // We may need a lot of memory here.
        @set_time_limit(0);
        raise_memory_limit(MEMORY_HUGE);

	$localuserfield   = $this->get_config('localuserfield', 'admins');
        $unenrolaction    = $this->get_config('unenrolaction');
        $defaultrole      = $this->get_config('defaultrole');
	$groupprefix      = $this->get_config('groupprefix', '');

        // Create roles mapping.
        $allroles = get_all_roles();
        if (!isset($allroles[$defaultrole])) {
            $defaultrole = 0;
        }
        $roles = array();
        foreach ($allroles as $role) {
            $roles[$role->shortname] = $role->id;
        }

        if ($onecourse) {
            $sql = "SELECT c.id, c.visible, c.shortname AS mapping, c.shortname, e.id AS enrolid
                      FROM {course} c
                 LEFT JOIN {enrol} e ON (e.courseid = c.id AND e.enrol = 'voot')
                     WHERE c.id = :id";
            if (!$course = $DB->get_record_sql($sql, array('id'=>$onecourse))) {
                // Course does not exist, nothing to sync.
                return 0;
            }
            if (empty($course->mapping)) {
                // We can not map to this course, sorry.
                return 0;
            }
            if (empty($course->enrolid)) {
                $course->enrolid = $this->add_instance($course);
            }
            $existing = array($course->mapping=>$course);

            // Feel free to unenrol everybody, no safety tricks here.
            $preventfullunenrol = false;
            // Course being restored are always hidden, we have to ignore the setting here.
            $ignorehidden = false;

        } else {
            // Get a list of courses to be synced that are in external table.
            if (!$externalcourses = $this->voot_getcourses()) {
                $trace->output('Error while communicating with external enrolment VOOT server');
                $trace->finished();
                return 2;
            }

            $preventfullunenrol = empty($externalcourses);
            if ($preventfullunenrol and $unenrolaction == ENROL_EXT_REMOVED_UNENROL) {
                $trace->output('Preventing unenrolment of all current users, because it might result in major data loss, there has to be at least one record in external enrol table, sorry.', 1);
            }

            // First find all existing courses with enrol instance.
            $existing = array();
            $sql = "SELECT c.id, c.visible, c.shortname AS mapping, e.id AS enrolid, c.shortname
                      FROM {course} c
                      JOIN {enrol} e ON (e.courseid = c.id AND e.enrol = 'voot')";
            $rs = $DB->get_recordset_sql($sql); // Watch out for idnumber duplicates.
            foreach ($rs as $course) {
                if (empty($course->mapping)) {
                    continue;
                }
                $existing[$course->mapping] = $course;
                unset($externalcourses[$groupprefix . $course->mapping]);
            }
            $rs->close();

            // Add necessary enrol instances that are not present yet.
            $params = array();
            $localnotempty = "";
            if ($localcoursefield !== 'id') {
                $localnotempty =  "AND c.shortname <> :lcfe";
                $params['lcfe'] = '';
            }
            $sql = "SELECT c.id, c.visible, c.shortname AS mapping, c.shortname
                      FROM {course} c
                 LEFT JOIN {enrol} e ON (e.courseid = c.id AND e.enrol = 'voot')
                     WHERE e.id IS NULL $localnotempty";
            $rs = $DB->get_recordset_sql($sql, $params);
            foreach ($rs as $course) {
                if (empty($course->mapping)) {
                    continue;
                }
                if (!isset($externalcourses[$groupprefix . $course->mapping])) {
                    // Course not synced or duplicate.
                    continue;
                }
                $course->enrolid = $this->add_instance($course);
                $existing[$groupprefix . $course->mapping] = $course;
                unset($externalcourses[$groupprefix . $course->mapping]);
            }
            $rs->close();

            // Print list of missing courses.
            if ($externalcourses) {
                $list = implode(', ', array_keys($externalcourses));
                $trace->output("error: following courses do not exist - $list", 1);
                unset($list);
            }

            // Free memory.
            unset($externalcourses);

            $ignorehidden = $this->get_config('ignorehiddencourses');
        }

        // Sync user enrolments.
        foreach ($existing as $course) {
            if ($ignorehidden and !$course->visible) {
                continue;
            }
            if (!$instance = $DB->get_record('enrol', array('id'=>$course->enrolid))) {
                continue; // Weird!
            }
            $context = context_course::instance($course->id);

            // Get current list of enrolled users with their roles.
            $current_roles  = array();
            $current_status = array();
            $user_mapping   = array();
            $sql = "SELECT u.username AS mapping, u.id, ue.status, ue.userid, ra.roleid
                      FROM {user} u
                      JOIN {user_enrolments} ue ON (ue.userid = u.id AND ue.enrolid = :enrolid)
                      JOIN {role_assignments} ra ON (ra.userid = u.id AND ra.itemid = ue.enrolid AND ra.component = 'enrol_voot')
                     WHERE u.deleted = 0";
            $params = array('enrolid'=>$instance->id);
            $sql .= " AND u.mnethostid = :mnethostid";
            $params['mnethostid'] = $CFG->mnet_localhost_id;
            $rs = $DB->get_recordset_sql($sql, $params);
            foreach ($rs as $ue) {
                $current_roles[$ue->userid][$ue->roleid] = $ue->roleid;
                $current_status[$ue->userid] = $ue->status;
                $user_mapping[$ue->mapping] = $ue->userid;
            }
            $rs->close();

            // Get list of users that need to be enrolled and their roles.
            if (!$coursenrolments = $this->voot_getmembers($course->mapping)) {
                $trace->output('Error while communicating with external enrolment VOOT server');
                $trace->finished();
                return 2;
            }

            foreach ($coursenrolments as $curenrolment) {
                $curenrolment = get_object_vars($curenrolment);
                $usersearch = array('deleted' => 0);
                $usersearch['mnethostid'] = $CFG->mnet_localhost_id;

                if (empty($curenrolment["id"])) {
                    $trace->output("error: skipping user without mandatory id in course '$course->mapping'", 1);
                    continue;
                }
                $mapping = $curenrolment["id"];
                if (!isset($user_mapping[$mapping])) {
                    $usersearch['username'] = $mapping;
                    if (!$user = $DB->get_record('user', $usersearch, 'id', IGNORE_MULTIPLE)) {
                        $trace->output("error: skipping unknown user username '$mapping' in course '$course->mapping'", 1);
                        continue;
                    }
                    $user_mapping[$mapping] = $user->id;
                    $userid = $user->id;
                } else {
                    $userid = $user_mapping[$mapping];
                }
                if (empty($curenrolment["voot_membership_role"]) or !isset($curenrolment["voot_membership_role"])) {
                    if (!$defaultrole) {
                        $trace->output("error: skipping user '$userid' in course '$course->mapping' - missing course and default role", 1);
                        continue;
                    }
                    $roleid = $defaultrole;
                } else {
                    $role = ($curenrolment["voot_membership_role"] == $localuserfield) ? "teacher" : "student";
                    $roleid = $roles[$role];
                }

                $requested_roles[$userid][$roleid] = $roleid;
            }
            unset($user_mapping);

            // Enrol all users and sync roles.
            foreach ($requested_roles as $userid=>$userroles) {
                foreach ($userroles as $roleid) {
                    if (empty($current_roles[$userid])) {
                        $this->enrol_user($instance, $userid, $roleid, 0, 0, ENROL_USER_ACTIVE);
                        $current_roles[$userid][$roleid] = $roleid;
                        $current_status[$userid] = ENROL_USER_ACTIVE;
                        $trace->output("enrolling: $userid ==> $course->shortname as ".$allroles[$roleid]->shortname, 1);
                    }
                }

                // Assign extra roles.
                foreach ($userroles as $roleid) {
                    if (empty($current_roles[$userid][$roleid])) {
                        role_assign($roleid, $userid, $context->id, 'enrol_voot', $instance->id);
                        $current_roles[$userid][$roleid] = $roleid;
                        $trace->output("assigning roles: $userid ==> $course->shortname as ".$allroles[$roleid]->shortname, 1);
                    }
                }

                // Unassign removed roles.
                foreach($current_roles[$userid] as $cr) {
                    if (empty($userroles[$cr])) {
                        role_unassign($cr, $userid, $context->id, 'enrol_voot', $instance->id);
                        unset($current_roles[$userid][$cr]);
                        $trace->output("unsassigning roles: $userid ==> $course->shortname", 1);
                    }
                }

                // Reenable enrolment when previously disable enrolment refreshed.
                if ($current_status[$userid] == ENROL_USER_SUSPENDED) {
                    $this->update_user_enrol($instance, $userid, ENROL_USER_ACTIVE);
                    $trace->output("unsuspending: $userid ==> $course->shortname", 1);
                }
            }

            // Deal with enrolments removed from external table.
            if ($unenrolaction == ENROL_EXT_REMOVED_UNENROL) {
                if (!$preventfullunenrol) {
                    // Unenrol.
                    foreach ($current_status as $userid=>$status) {
                        if (isset($requested_roles[$userid])) {
                            continue;
                        }
                        $this->unenrol_user($instance, $userid);
                        $trace->output("unenrolling: $userid ==> $course->shortname", 1);
                    }
                }

            } else if ($unenrolaction == ENROL_EXT_REMOVED_KEEP) {
                // Keep - only adding enrolments.

            } else if ($unenrolaction == ENROL_EXT_REMOVED_SUSPEND or $unenrolaction == ENROL_EXT_REMOVED_SUSPENDNOROLES) {
                // Suspend enrolments.
                foreach ($current_status as $userid=>$status) {
                    if (isset($requested_roles[$userid])) {
                        continue;
                    }
                    if ($status != ENROL_USER_SUSPENDED) {
                        $this->update_user_enrol($instance, $userid, ENROL_USER_SUSPENDED);
                        $trace->output("suspending: $userid ==> $course->shortname", 1);
                    }
                    if ($unenrolaction == ENROL_EXT_REMOVED_SUSPENDNOROLES) {
                        role_unassign_all(array('contextid'=>$context->id, 'userid'=>$userid, 'component'=>'enrol_voot', 'itemid'=>$instance->id));
                        $trace->output("unsassigning all roles: $userid ==> $course->shortname", 1);
                    }
                }
            }
        }

        $trace->output('...user enrolment synchronisation finished.');
        $trace->finished();

        return 0;
    }

    /**
     * Performs a full sync with external VOOT server.
     *
     * First it creates new courses if necessary, then
     * enrols and unenrols users.
     *
     * @param progress_trace $trace
     * @return int 0 means success, 1 db connect failure, 4 db read failure
     */
    public function sync_courses(progress_trace $trace) {
        global $CFG, $DB;

        // Make sure we sync either enrolments or courses.
        if (!$this->get_config('voothost') or !$this->get_config('urlprefix')) {
            $trace->output('Course synchronisation skipped.');
            $trace->finished();
            return 0;
        }

        $trace->output('Starting course synchronisation...');

        // We may need a lot of memory here.
        @set_time_limit(0);
        raise_memory_limit(MEMORY_HUGE);

        if (!$courses = $this->voot_getcourses()) {
            $trace->output('Error while communicating with external enrolment VOOT server');
            $trace->finished();
            return 1;
        }

        $fullname  = trim($this->get_config('newcoursefullname'));
        $shortname = trim($this->get_config('newcourseshortname'));

	$localcoursefield   = intval($this->get_config('localcoursefield', '0'));
        $localcategoryfield = $this->get_config('localcategoryfield', 'id');
        $defaultcategory    = $this->get_config('defaultcategory');

        if (!$DB->record_exists('course_categories', array('id'=>$defaultcategory))) {
            $trace->output("default course category does not exist!", 1);
            $categories = $DB->get_records('course_categories', array(), 'sortorder', 'id', 0, 1);
            $first = reset($categories);
            $defaultcategory = $first->id;
        }

        $sqlfields = array($fullname, $shortname);
        if ($category) {
            $sqlfields[] = $category;
        }
        if ($idnumber) {
            $sqlfields[] = $idnumber;
        }

        $createcourses = array();
        foreach ($courses as $curcourse) {
            $parts = explode(':', $curcourse->$shortname);
            $course_shortname = $parts[$localcoursefield];
            $parts = explode(':', $curcourse->$fullname);
            $course_fullname = $parts[$localcoursefield];

            if (empty($course_shortname) or empty($course_fullname)) {
                $trace->output('error: invalid external course record, shortname and fullname are mandatory: ' . json_encode($curcourse), 1); // Hopefully every geek can read JS, right?
                continue;
            }
            if ($DB->record_exists('course', array('shortname'=>$course_shortname))) {
                 $trace->output('course ' . $course_shortname . ' already exists, skipping.');
                 // Already exists, skip.
                 continue;
            }

            $course = new stdClass();
            $course->fullname  = $course_fullname;
            $course->shortname = $course_shortname;
            $course->idnumber  = '';
            $course->category = $defaultcategory;

            $createcourses[] = $course;
        }

        if ($createcourses) {
            require_once("$CFG->dirroot/course/lib.php");

            $templatecourse = $this->get_config('templatecourse');

            $template = false;
            if ($templatecourse) {
                if ($template = $DB->get_record('course', array('shortname'=>$templatecourse))) {
                    $template = fullclone(course_get_format($template)->get_course());
                    unset($template->id);
                    unset($template->fullname);
                    unset($template->shortname);
                    unset($template->idnumber);
                } else {
                    $trace->output("can not find template for new course!", 1);
                }
            }
            if (!$template) {
                $courseconfig = get_config('moodlecourse');
                $template = new stdClass();
                $template->summary        = '';
                $template->summaryformat  = FORMAT_HTML;
                $template->format         = $courseconfig->format;
                $template->newsitems      = $courseconfig->newsitems;
                $template->showgrades     = $courseconfig->showgrades;
                $template->showreports    = $courseconfig->showreports;
                $template->maxbytes       = $courseconfig->maxbytes;
                $template->groupmode      = $courseconfig->groupmode;
                $template->groupmodeforce = $courseconfig->groupmodeforce;
                $template->visible        = $courseconfig->visible;
                $template->lang           = $courseconfig->lang;
                $template->groupmodeforce = $courseconfig->groupmodeforce;
            }

            foreach ($createcourses as $fields) {
                $newcourse = clone($template);
                $newcourse->fullname  = $fields->fullname;
                $newcourse->shortname = $fields->shortname;
                $newcourse->idnumber  = $fields->idnumber;
                $newcourse->category  = $fields->category;

                // Detect duplicate data once again, above we can not find duplicates
                // in external data using DB collation rules...
                if ($DB->record_exists('course', array('shortname' => $newcourse->shortname))) {
                    $trace->output("can not insert new course, duplicate shortname detected: ".$newcourse->shortname, 1);
                    continue;
                } else if (!empty($newcourse->idnumber) and $DB->record_exists('course', array('idnumber' => $newcourse->idnumber))) {
                    $trace->output("can not insert new course, duplicate idnumber detected: ".$newcourse->idnumber, 1);
                    continue;
                }
                $c = create_course($newcourse);
                $trace->output("creating course: $c->id, $c->fullname, $c->shortname, $c->idnumber, $c->category", 1);
            }

            unset($createcourses);
            unset($template);
        }

        $trace->output('...course synchronisation finished.');
        $trace->finished();

        return 0;
    }

    protected function getSslPage($url, $username, $password) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_REFERER, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        if (!empty($username) and !empty($password)) {
		curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
	}
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    /**
     * Tries to make connection to the external VOOT server.
     *
     * @return null|mixedJSON
     */
    protected function voot_getcourses() {
        global $CFG;

	$groupprefix = $this->get_config('groupprefix', '');
        $shortname = trim($this->get_config('newcourseshortname', 'id'));
	$url = $this->get_config('vootproto') . "://" . $this->get_config('voothost') . $this->get_config('urlprefix') . "/groups";
	$pagecontent = $this->getSslPage($url, $this->get_config('vootuser'), $this->get_config('vootpass'));

	$courses = json_decode($pagecontent);
	if (json_last_error() === JSON_ERROR_NONE) {
		$courses = get_object_vars($courses);
		$courses = $courses['entry'];
		$valret = array();

		if ($groupprefix == '') {
			return $courses;
		}
		else {
			foreach($courses as $curcourse) {
				if (strpos($curcourse->$shortname, $groupprefix) === 0) {
					$valret[$curcourse->$shortname] = $curcourse;
				}
			}
			return $valret;
		}
	}

	return NULL;
    }

    /**
     * Tries to make connection to the external VOOT server.
     *
     * @return null|mixedJSON
     */
    protected function voot_getmembers($courseid) {
        global $CFG;

	$groupprefix = $this->get_config('groupprefix', '');
	$url = $this->get_config('vootproto') . "://" . $this->get_config('voothost') . $this->get_config('urlprefix') . "/people/@me/" . $groupprefix . $courseid;
	$pagecontent = $this->getSslPage($url, $this->get_config('vootuser'), $this->get_config('vootpass'));

	$members = json_decode($pagecontent);
	if (json_last_error() === JSON_ERROR_NONE) { 
		$members = get_object_vars($members);
                $members = $members['entry'];
		return $members;
	}

	return NULL;
    }

    /**
     * Tries to make connection to the external VOOT server.
     *
     * @return null|mixedJSON
     */
    protected function voot_getenrolments($user) {
        global $CFG;

	$groupprefix = get_config('groupprefix', '');
	$url = $this->get_config('vootproto') . "://" . $this->get_config('voothost') . $this->get_config('urlprefix') . "/groups/" . $yser . "/";
	$pagecontent = $this->getSslPage($url, $this->get_config('vootuser'), $this->get_config('vootpass'));

	$members = json_decode($pagecontent);
	if (json_last_error() === JSON_ERROR_NONE) { 
		$members = get_object_vars($members);
                $members = $members['entry'];
		return $members;
	}

	return NULL;
    }

    /**
     * Automatic enrol sync executed during restore.
     * @param stdClass $course course record
     */
    public function restore_sync_course($course) {
        $trace = new null_progress_trace();
        $this->sync_enrolments($trace, $course->id);
    }

    /**
     * Restore instance and map settings.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $course
     * @param int $oldid
     */
    public function restore_instance(restore_enrolments_structure_step $step, stdClass $data, $course, $oldid) {
        global $DB;
        if ($instance = $DB->get_record('enrol', array('courseid'=>$course->id, 'enrol'=>$this->get_name()))) {
            $instanceid = $instance->id;
        } else {
            $instanceid = $this->add_instance($course);
        }
        $step->set_mapping('enrol', $oldid, $instanceid);
    }

    /**
     * Restore user enrolment.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $instance
     * @param int $oldinstancestatus
     * @param int $userid
     */
    public function restore_user_enrolment(restore_enrolments_structure_step $step, $data, $instance, $userid, $oldinstancestatus) {
        global $DB;

        if ($this->get_config('unenrolaction') == ENROL_EXT_REMOVED_UNENROL) {
            // Enrolments were already synchronised in restore_instance(), we do not want any suspended leftovers.
            return;
        }
        if (!$DB->record_exists('user_enrolments', array('enrolid'=>$instance->id, 'userid'=>$userid))) {
            $this->enrol_user($instance, $userid, null, 0, 0, ENROL_USER_SUSPENDED);
        }
    }

    /**
     * Restore role assignment.
     *
     * @param stdClass $instance
     * @param int $roleid
     * @param int $userid
     * @param int $contextid
     */
    public function restore_role_assignment($instance, $roleid, $userid, $contextid) {
        if ($this->get_config('unenrolaction') == ENROL_EXT_REMOVED_UNENROL or $this->get_config('unenrolaction') == ENROL_EXT_REMOVED_SUSPENDNOROLES) {
            // Role assignments were already synchronised in restore_instance(), we do not want any leftovers.
            return;
        }
        role_assign($roleid, $userid, $contextid, 'enrol_'.$this->get_name(), $instance->id);
    }

    /**
     * Test plugin settings, print info to output.
     */
    public function test_settings() {
        global $CFG, $OUTPUT;

        // NOTE: this is not localised intentionally, admins are supposed to understand English at least a bit...

        raise_memory_limit(MEMORY_HUGE);

        $this->load_config();

        $voothost = $this->get_config('voothost');
        $urlprefix = $this->get_config('urlprefix');

        if (empty($voothost)) {
            echo $OUTPUT->notification('Host with VOOT interface not specified.', 'notifyproblem');
        }

        if (empty($urlprefix)) {
            echo $OUTPUT->notification('URL prefix for VOOT interface not specified.', 'notifyproblem');
        }

        $olddebug = $CFG->debug;
        $olddisplay = ini_get('display_errors');
        ini_set('display_errors', '1');
        $CFG->debug = DEBUG_DEVELOPER;
        error_reporting($CFG->debug);

        $returnedpage = $this->voot_getcourses();

	$first_course = '';
        if (!$returnedpage) {
            $CFG->debug = $olddebug;
            ini_set('display_errors', $olddisplay);
            error_reporting($CFG->debug);
            ob_end_flush();

            echo $OUTPUT->notification('Cannot connect to the VOOT server.', 'notifyproblem');
        }
	else {
		$first_course = $returnedpage->entry[0]->id;
		echo $OUTPUT->notification('External course call retrieves the following fields:<br />'. implode(', ', array_keys(get_object_vars($returnedpage->entry[0]))), 'notifysuccess');
	}

	if (!empty($first_course)) {
	        $returnedpage = $this->voot_getmembers($first_course);
        	if (!$returnedpage) {
	            $CFG->debug = $olddebug;
        	    ini_set('display_errors', $olddisplay);
	            error_reporting($CFG->debug);
        	    ob_end_flush();

	            echo $OUTPUT->notification('Cannot connect to the VOOT server.', 'notifyproblem');
	        }
		else {
			echo $OUTPUT->notification('External enrolment call retrieves the following fields:<br />'. implode(', ', array_keys(get_object_vars($returnedpage->entry[0]))), 'notifysuccess');
		}
	}

        $CFG->debug = $olddebug;
        ini_set('display_errors', $olddisplay);
        error_reporting($CFG->debug);
        ob_end_flush();
    }
}
