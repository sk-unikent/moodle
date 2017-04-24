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
 * Adhoc task manager, schedules and runs adhoc tasks.
 *
 * @package    core
 * @category   task
 * @copyright  2017 Skylar kelty
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace core\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Adhoc task manager, schedules and runs adhoc tasks.
 *
 * @copyright  2017 Skylar kelty
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adhoc_manager_db extends adhoc_manager {
    /**
     * Queue an adhoc task to run in the background.
     *
     * @param \core\task\adhoc_task $task - The new adhoc task information to store.
     * @return boolean - True if the config was saved.
     */
    public function queue_adhoc_task(adhoc_task $task) {
        global $DB;

        $record = self::record_from_adhoc_task($task);
        // Schedule it immediately if nextruntime not explicitly set.
        if (!$task->get_next_run_time()) {
            $record->nextruntime = time() - 1;
        }
        $result = $DB->insert_record('task_adhoc', $record);

        return $result;
    }

    /**
     * Utility method to create a DB record from an adhoc task.
     *
     * @param \core\task\adhoc_task $task
     * @return \stdClass
     */
    public static function record_from_adhoc_task($task) {
        $record = new \stdClass();
        $record->classname = get_class($task);
        if (strpos($record->classname, '\\') !== 0) {
            $record->classname = '\\' . $record->classname;
        }
        $record->id = $task->get_id();
        $record->component = $task->get_component();
        $record->blocking = $task->is_blocking();
        $record->nextruntime = $task->get_next_run_time();
        $record->faildelay = $task->get_fail_delay();
        $record->customdata = $task->get_custom_data_as_string();

        return $record;
    }

    /**
     * Utility method to create an adhoc task from a DB record.
     *
     * @param \stdClass $record
     * @return \core\task\adhoc_task
     */
    public static function adhoc_task_from_record($record) {
        $classname = $record->classname;
        if (strpos($classname, '\\') !== 0) {
            $classname = '\\' . $classname;
        }
        if (!class_exists($classname)) {
            debugging("Failed to load task: " . $classname, DEBUG_DEVELOPER);
            return false;
        }
        $task = new $classname;
        if (isset($record->nextruntime)) {
            $task->set_next_run_time($record->nextruntime);
        }
        if (isset($record->id)) {
            $task->set_id($record->id);
        }
        if (isset($record->component)) {
            $task->set_component($record->component);
        }
        $task->set_blocking(!empty($record->blocking));
        if (isset($record->faildelay)) {
            $task->set_fail_delay($record->faildelay);
        }
        if (isset($record->customdata)) {
            $task->set_custom_data_as_string($record->customdata);
        }

        return $task;
    }

    /**
     * This function will dispatch the next adhoc task in the queue. The task will be handed out
     * with an open lock - possibly on the entire cron process. Make sure you call either
     * {@link adhoc_task_failed} or {@link adhoc_task_complete} to release the lock and reschedule the task.
     *
     * @param int $timestart
     * @return \core\task\adhoc_task or null if not found
     */
    public function get_next_adhoc_task($timestart) {
        global $DB;
        $cronlockfactory = \core\lock\lock_config::get_lock_factory('cron');

        if (!$cronlock = $cronlockfactory->get_lock('core_cron', 10)) {
            throw new \moodle_exception('locktimeout');
        }

        $where = '(nextruntime IS NULL OR nextruntime < :timestart1)';
        $params = array('timestart1' => $timestart);
        $records = $DB->get_records_select('task_adhoc', $where, $params);

        foreach ($records as $record) {

            if ($lock = $cronlockfactory->get_lock('adhoc_' . $record->id, 0)) {
                $classname = '\\' . $record->classname;

                // Safety check, see if the task has been already processed by another cron run.
                $record = $DB->get_record('task_adhoc', array('id' => $record->id));
                if (!$record) {
                    $lock->release();
                    continue;
                }

                $task = self::adhoc_task_from_record($record);
                // Safety check in case the task in the DB does not match a real class (maybe something was uninstalled).
                if (!$task) {
                    $lock->release();
                    continue;
                }

                $task->set_lock($lock);
                if (!$task->is_blocking()) {
                    $cronlock->release();
                } else {
                    $task->set_cron_lock($cronlock);
                }
                return $task;
            }
        }

        // No tasks.
        $cronlock->release();
        return null;
    }

    /**
     * This function indicates that an adhoc task was not completed successfully and should be retried.
     *
     * @param \core\task\adhoc_task $task
     */
    public function adhoc_task_failed(adhoc_task $task) {
        global $DB;
        $delay = $task->get_fail_delay();

        // Reschedule task with exponential fall off for failing tasks.
        if (empty($delay)) {
            $delay = 60;
        } else {
            $delay *= 2;
        }

        // Max of 24 hour delay.
        if ($delay > 86400) {
            $delay = 86400;
        }

        $classname = get_class($task);
        if (strpos($classname, '\\') !== 0) {
            $classname = '\\' . $classname;
        }

        $task->set_next_run_time(time() + $delay);
        $task->set_fail_delay($delay);
        $record = self::record_from_adhoc_task($task);
        $DB->update_record('task_adhoc', $record);

        if ($task->is_blocking()) {
            $task->get_cron_lock()->release();
        }
        $task->get_lock()->release();
    }

    /**
     * This function indicates that an adhoc task was completed successfully.
     *
     * @param \core\task\adhoc_task $task
     */
    public function adhoc_task_complete(adhoc_task $task) {
        global $DB;

        // Delete the adhoc task record - it is finished.
        $DB->delete_records('task_adhoc', array('id' => $task->get_id()));

        // Reschedule and then release the locks.
        if ($task->is_blocking()) {
            $task->get_cron_lock()->release();
        }
        $task->get_lock()->release();
    }
}
