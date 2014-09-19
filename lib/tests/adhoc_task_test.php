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
 * This file contains the unittests for adhock tasks.
 *
 * @package   core
 * @category  phpunit
 * @copyright 2013 Damyon Wiese
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/fixtures/task_fixtures.php');


/**
 * Test class for adhoc tasks.
 *
 * @package core
 * @category task
 * @copyright 2013 Damyon Wiese
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class core_adhoc_task_testcase extends advanced_testcase {

    public function test_get_next_adhoc_task() {
        $this->resetAfterTest(true);
        // Create an adhoc task.
        $task = new \core\task\adhoc_test_task();

        // Queue it.
        $task = \core\task\manager::queue_adhoc_task($task);

        $now = time();
        // Get it from the scheduler.
        $task = \core\task\manager::get_next_adhoc_task($now);
        $this->assertNotNull($task);
        $task->execute();

        \core\task\manager::adhoc_task_failed($task);
        // Should not get any task.
        $task = \core\task\manager::get_next_adhoc_task($now);
        $this->assertNull($task);

        // Should get the adhoc task (retry after delay).
        $task = \core\task\manager::get_next_adhoc_task($now + 120);
        $this->assertNotNull($task);
        $task->execute();

        \core\task\manager::adhoc_task_complete($task);

        // Should not get any task.
        $task = \core\task\manager::get_next_adhoc_task($now);
        $this->assertNull($task);
    }

    public function test_adhoc_task_queue_fail() {
        global $DB;

        $this->resetAfterTest(true);

        // Create a task that should fail.
        $DB->insert_record('task_adhoc', array(
            'id' => 1,
            'classname' => 'thiswillfail',
            'component' => 'core',
            'blocking' => 0,
            'nextruntime' => time() - 1,
            'faildelay' => 0,
            'customdata' => ''
        ));


        // Create an adhoc task.
        $task = new \core\task\adhoc_test_task();

        // Queue it.
        $task = \core\task\manager::queue_adhoc_task($task);

        // Get it from the scheduler, this will fail if get_next_adhoc_task returns the invalid
        // object we created first.
        $task = \core\task\manager::get_next_adhoc_task(time());
        $this->assertNotNull($task);
        $task->execute();

        \core\task\manager::adhoc_task_complete($task);
    }
}
