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
 * Defines classes used for the recyclebin.
 *
 * @package    core
 * @copyright  2016 Skylar Kelty
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace core\recyclebin;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles module backup/restore as part of the recyclebin feature.
 */
class module extends item {
    /**
     * Handles the delete event for activities.
     *
     * @param string $modname The module name.
     * @param \stdClass $cm The course module record.
     */
    public static function delete_instance($modname, $cm) {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

        // Check backup/restore support.
        if (!plugin_supports('mod', $modname, FEATURE_BACKUP_MOODLE2)) {
            return;
        }

        // Backup the activity as an admin user.
        $user = get_admin();
        $controller = new \backup_controller(
            \backup::TYPE_1ACTIVITY,
            $cm->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $user->id
        );
        $controller->execute_plan();

        // Grab the result.
        $result = $controller->get_results();
        if (!isset($result['backup_destination'])) {
            debugging('Failed to backup activity to recyclebin prior to deletion.');
            return false;
        }

        // Grab the filename.
        $file = $result['backup_destination'];
        if (!$file->get_contenthash()) {
            debugging('Failed to backup activity to recyclebin prior to deletion (could not find file).');
            return false;
        }

        // Make sure our backup dir exists.
        $bindir = $CFG->dataroot . '/recyclebin';
        if (!file_exists($bindir)) {
            make_writable_directory($bindir);
        }

        // Get more information.
        $modinfo = get_fast_modinfo($cm->course);
        $cminfo = $modinfo->cms[$cm->id];

        // Record the activity, get an ID.
        $coursectx = \context_course::instance($cm->course);
        $binid = $DB->insert_record('backup_recyclebin', array(
            'contextid' => $coursectx->id,
            'data' => json_encode(array(
                'section' => $cm->section,
                'module' => $cm->module,
                'name' => $cminfo->name,
            )),
            'deleted' => time()
        ));

        // Move the file to our own special little place.
        if (!$file->copy_content_to($bindir . '/' . $binid)) {
            // Failed, cleanup first.
            $DB->delete_records('backup_recyclebin', array(
                'id' => $binid
            ));

            debugging('Failed to copy activity backup to recyclebin prior to deletion.');
            return false;
        }

        // Delete the old file.
        $file->delete();

        return true;
    }

    /**
     * Restore this item.
     */
    public function restore() {
        global $CFG;

        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $user = get_admin();

        // Get the pathname.
        $source = $CFG->dataroot . '/recyclebin/' . $this->id;
        if (!file_exists($source)) {
            throw new \moodle_exception('Invalid recycle bin item!');
        }

        // Grab a tmpdir.
        $tmpdir = \restore_controller::get_tempdir_name($this->context->id, $user->id);

        // Extract the backup to tmpdir.
        $fb = get_file_packer('application/vnd.moodle.backup');
        $fb->extract_to_pathname($source, $CFG->tempdir . '/backup/' . $tmpdir . '/');

        // Define the import.
        $controller = new \restore_controller(
            $tmpdir,
            $this->context->instanceid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $user->id,
            \backup::TARGET_EXISTING_ADDING
        );

        // Prechecks.
        if (!$controller->execute_precheck()) {
            $results = $controller->get_precheck_results();
            if (isset($results['errors'])) {
                debugging(var_export($results, true));

                return false;
            }

            if (isset($results['warnings'])) {
                debugging(var_export($results['warnings'], true));
            }
        }

        // Run the import.
        $controller->execute_plan();

        // Cleanup.
        $this->delete();

        return true;
    }
}
