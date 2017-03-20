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
 * Base class for queue managers.
 *
 * @package   tool_adhoc
 * @author    Skylar Kelty <S.Kelty@kent.ac.uk>
 * @copyright 2017 Skylar Kelty <S.Kelty@kent.ac.uk>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class queue
{
    /**
     * Supports delayed tasks.
     */
    const SUPPORTS_DELAYS = 1;

    /**
     * Supports task priorities.
     */
    const SUPPORTS_PRIORITIES = 2;

    /**
     * Supports task timeouts.
     */
    const SUPPORTS_TIMEOUTS = 4;

    /**
     * Returns the supported features as a combined int.
     *
     * @param array $configuration
     * @return int
     */
    public static function get_supported_features(array $configuration = array()) {
        return 0;
    }

    /**
     * Returns true if the store instance supports delayed tasks.
     *
     * @return bool
     */
    public function supports_delay() {
        return $this::get_supported_features() & self::SUPPORTS_DELAYS;
    }

    /**
     * Returns true if the store instance supports priority ordering.
     *
     * @return bool
     */
    public function supports_priority() {
        return $this::get_supported_features() & self::SUPPORTS_PRIORITIES;
    }

    /**
     * Push an item onto the queue.
     *
     * @param  stdClass $task The task object.
     * @param  int $priority  Priority (0 = highest priority)
     * @param  int $timeout   Timeout for the task to complete (seconds).
     * @return bool           True on success, false otherwise.
     */
    public abstract function push($task, $priority = 1, $timeout = 900);

    /**
     * Are we ready?
     *
     * @return bool True if ready, false otherwise.
     */
    public abstract function is_ready();
}
