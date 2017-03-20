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
 * Cron queue for the adhoc task manager.
 *
 * @package   queue_cron
 * @author    Skylar Kelty <S.Kelty@kent.ac.uk>
 * @copyright 2017 Skylar Kelty <S.Kelty@kent.ac.uk>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace queue_cron;

defined('MOODLE_INTERNAL') || die();

/**
 * Cron queue API.
 *
 * @package   queue_cron
 * @author    Skylar Kelty <S.Kelty@kent.ac.uk>
 * @copyright 2017 Skylar Kelty <S.Kelty@kent.ac.uk>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class queue extends \tool_adhoc\queue
{
    /**
     * @var \stdClass Plugin config.
     */
    private $config;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->config = get_config('queue_cron');
    }

    /**
     * Returns the supported features as a combined int.
     *
     * @param array $configuration
     * @return int
     */
    public static function get_supported_features(array $configuration = array()) {
        return \tool_adhoc\queue::SUPPORTS_DELAYS;
    }

    /**
     * Push an item onto the queue.
     *
     * @param  stdClass $task The task object.
     * @param  int $priority  Priority (0 = highest priority)
     * @param  int $timeout   Timeout for the task to complete (seconds).
     * @return bool           True on success, false otherwise.
     */
    public function push($task, $priority = 1, $timeout = 900) {
        global $DB;

        $record = \core\task\manager::record_from_adhoc_task($task);

        // Schedule it immediately if nextruntime not explicitly set.
        if (!$task->get_next_run_time()) {
            $record->nextruntime = time() - 1;
        }

        return $DB->insert_record('task_adhoc', $record);
    }

    /**
     * Are we ready?
     * The DB is always ready if we are checking this.
     */
    public function is_ready() {
        return true;
    }
}
