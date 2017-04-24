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
abstract class adhoc_manager {
    /**
     * Queue an adhoc task to run in the background.
     *
     * @param \core\task\adhoc_task $task - The new adhoc task information to store.
     * @param bool $checkforexisting - If set to true and the task with the same classname, component and customdata
     *     is already scheduled then it will not schedule a new task. Can be used only for ASAP tasks.
     * @return boolean - True if the config was saved.
     */
    public abstract function queue_adhoc_task(adhoc_task $task, $checkforexisting = false);

    /**
     * Checks if the task with the same classname, component and customdata is already scheduled
     *
     * @param adhoc_task $task
     * @return bool
     */
    protected abstract function task_is_scheduled($task);

    /**
     * This function load the adhoc tasks for a given classname.
     *
     * @param string $classname
     * @return \core\task\adhoc_task[]
     */
    public abstract function get_adhoc_tasks($classname);

    /**
     * This function will dispatch the next adhoc task in the queue. The task will be handed out
     * with an open lock - possibly on the entire cron process. Make sure you call either
     * {@link adhoc_task_failed} or {@link adhoc_task_complete} to release the lock and reschedule the task.
     *
     * @param int $timestart
     * @return \core\task\adhoc_task or null if not found
     */
    public abstract function get_next_adhoc_task($timestart);

    /**
     * This function indicates that an adhoc task was not completed successfully and should be retried.
     *
     * @param \core\task\adhoc_task $task
     */
    public abstract function adhoc_task_failed(adhoc_task $task);

    /**
     * This function indicates that an adhoc task was completed successfully.
     *
     * @param \core\task\adhoc_task $task
     */
    public abstract function adhoc_task_complete(adhoc_task $task);

    /**
     *
     * Delete an adhoc task from the queue.
     *
     * @param \core\task\adhoc_task $task
     */
    public abstract function adhoc_task_delete(adhoc_task $task);

    /**
     * Cron run.
     */
    public function cron($timenow) {
        while (!\core\task\manager::static_caches_cleared_since($timenow) &&
               $task = $this->get_next_adhoc_task($timenow)) {
            $this->cron_run_inner_adhoc_task($task);
            unset($task);
        }
    }

    /**
     * Shared code that handles running of a single adhoc task within the cron.
     *
     * @param \core\task\adhoc_task $task
     */
    public function cron_run_inner_adhoc_task(\core\task\adhoc_task $task) {
        global $DB, $CFG;

        mtrace("Execute adhoc task: " . get_class($task));
        cron_trace_time_and_memory();
        $predbqueries = null;
        $predbqueries = $DB->perf_get_queries();
        $pretime      = microtime(1);

        if ($userid = $task->get_userid()) {
            // This task has a userid specified.
            if ($user = \core_user::get_user($userid)) {
                // User found. Check that they are suitable.
                try {
                    \core_user::require_active_user($user, true, true);
                } catch (\moodle_exception $e) {
                    mtrace("User {$userid} cannot be used to run an adhoc task: " . get_class($task) . ". Cancelling task.");
                    $user = null;
                }
            } else {
                // Unable to find the user for this task.
                // A user missing in the database will never reappear.
                mtrace("User {$userid} could not be found for adhoc task: " . get_class($task) . ". Cancelling task.");
            }

            if (empty($user)) {
                // A user missing in the database will never reappear so the task needs to be failed
                // to ensure that locks are removed, and then removed to prevent future runs.
                // A task running as a user should only be run as that user.
                $this->adhoc_task_failed($task);
                $this->adhoc_task_delete($task);

                return;
            }

            cron_setup_user($user);
        }

        try {
            get_mailer('buffer');
            $task->execute();
            if ($DB->is_transaction_started()) {
                throw new \coding_exception("Task left transaction open");
            }
            if (isset($predbqueries)) {
                mtrace("... used " . ($DB->perf_get_queries() - $predbqueries) . " dbqueries");
                mtrace("... used " . (microtime(1) - $pretime) . " seconds");
            }
            mtrace("Adhoc task complete: " . get_class($task));
            $this->adhoc_task_complete($task);
        } catch (\Exception $e) {
            if ($DB && $DB->is_transaction_started()) {
                debugging('Database transaction aborted automatically in ' . get_class($task));
                $DB->force_transaction_rollback();
            }

            if (isset($predbqueries)) {
                mtrace("... used " . ($DB->perf_get_queries() - $predbqueries) . " dbqueries");
                mtrace("... used " . (microtime(1) - $pretime) . " seconds");
            }

            mtrace("Adhoc task failed: " . get_class($task) . "," . $e->getMessage());
            if ($CFG->debugdeveloper) {
                if (!empty($e->debuginfo)) {
                    mtrace("Debug info:");
                    mtrace($e->debuginfo);
                }
                mtrace("Backtrace:");
                mtrace(format_backtrace($e->getTrace(), true));
            }

            $this->adhoc_task_failed($task);
        } finally {
            // Reset back to the standard admin user.
            cron_setup_user();
        }

        get_mailer('close');
    }
}
