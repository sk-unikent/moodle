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
 * Main API for the adhoc task manager.
 *
 * @package    tool_adhoc
 * @author     Skylar Kelty <S.Kelty@kent.ac.uk>
 * @copyright  2017 Skylar Kelty <S.Kelty@kent.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_adhoc;

defined('MOODLE_INTERNAL') || die();

/**
 * Adhoc manager methods.
 *
 * @package   tool_adhoc
 * @author    Skylar Kelty <S.Kelty@kent.ac.uk>
 * @copyright 2017 Skylar Kelty <S.Kelty@kent.ac.uk>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager
{
    /**
     * Given the name of a queue, returns the interface.
     *
     * @param string $queue The name of the queue.
     * @return \tool_adhoc\queue The queue object.
     */
    public static function get_queue($queue) {
        static $map = array();

        if (!isset($map[$queue])) {
            $class = "\\$queue\\queue";
            $map[$queue] = new $class();
        }

        return $map[$queue];
    }

    /**
     * Returns all queues in order.
     */
    public static function get_queues() {
        $enabled = get_config('tool_adhoc', 'enabled_queues');
        if (!$enabled) {
            return array();
        }

        $plugins = explode(',', $enabled);
        return array_map(array("\\tool_adhoc\\manager", 'get_queue'), $plugins);
    }

    /**
     * Check a plugin is enabled.
     *
     * @param string $plugin The name of the queue.
     * @return bool True if the plugin is enabled, false if not.
     */
    public static function is_enabled($plugin) {
        $enabled = get_config('tool_adhoc', 'enabled_queues');
        if (!$enabled) {
            return false;
        }

        $enabled = explode(',', $enabled);
        return in_array("queue_{$plugin}", $enabled);
    }

    /**
     * Hook for queue_adhoc_task.
     * This will keep trying queues in order until one successfully queues the task.
     *
     * @param  stdClass $task The task object.
     * @param  int $priority  Priority (0 = highest priority)
     * @param  int $timeout   Timeout for the task to complete (seconds).
     * @return bool           True on success, false otherwise.
     */
    public static function queue_adhoc_task($task, $priority = 1, $timeout = 900) {
        $queues = self::get_queues();
        foreach ($queues as $queue) {
            if ($queue->is_ready() && $queue->push($task, $priority, $timeout)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run an adhoc task.
     *
     * @param stdClass $record The task to run.
     * @return bool True if we succeeded, false if we didnt.
     */
    public static function run_task($record) {
        global $CFG, $DB;

        // Grab the task.
        $task = \core\task\manager::adhoc_task_from_record($record);
        if (!$task) {
            cli_writeln("Task '{$record->id}' could not be loaded.");
            return false;
        }

        // Grab a task lock.
        $cronlockfactory = \core\lock\lock_config::get_lock_factory('cron');
        if (!$tasklock = $cronlockfactory->get_lock('adhoc_' . $record->id, 600)) {
            cli_writeln('Cannot obtain task lock.');
            return false;
        }

        // Set lock info on task.
        $task->set_lock($tasklock);
        if ($task->is_blocking()) {
            if (!$cronlock = $cronlockfactory->get_lock('core_cron', 10)) {
                cli_writeln('Cannot obtain cron lock');
                return false;
            }

            $task->set_cron_lock($cronlock);
        }

        try {
            get_mailer('buffer');

            // Run the task.
            $task->execute();

            // Set the task as complete.
            \core\task\manager::adhoc_task_complete($task);
        } catch (\Exception $e) {
            if ($DB->is_transaction_started()) {
                $DB->force_transaction_rollback();
            }

            \core\task\manager::adhoc_task_failed($task);

            if ($CFG->debugdeveloper) {
                if (!empty($e->debuginfo)) {
                    cli_writeln("Debug info:");
                    cli_writeln($e->debuginfo);
                }

                cli_writeln("Backtrace:");
                cli_writeln(format_backtrace($e->getTrace(), true));
            }
        }

        get_mailer('close');

        return true;
    }
}
