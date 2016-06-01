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
 * @copyright 2016 Skylar Kelty <S.Kelty@kent.ac.uk>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace queue_cron\task;

/**
 * Task runner.
 */
class runtasks extends \core\task\scheduled_task
{
    public function get_name() {
        return 'Cron queue runner';
    }

    public function execute() {
        global $DB;

        $config = get_config('queue_cron');

        // Run all adhoc tasks.
        $count = 0;
        while (($config->maxtasks == 0 || $count < $config->maxtasks) &&
               !\core\task\manager::static_caches_cleared_since($timenow) &&
               $task = \core\task\manager::get_next_adhoc_task($timenow)) {
            $count++;
            cli_writeln('Execute adhoc task: ' . get_class($task));
            cron_trace_time_and_memory();
            $predbqueries = null;
            $predbqueries = $DB->perf_get_queries();
            $pretime      = microtime(1);

            try {
                get_mailer('buffer');
                $task->execute();
                if ($DB->is_transaction_started()) {
                    throw new \coding_exception('Task left transaction open');
                }

                if (isset($predbqueries)) {
                    cli_writeln('... used ' . ($DB->perf_get_queries() - $predbqueries) . ' dbqueries');
                    cli_writeln('... used ' . (microtime(1) - $pretime) . ' seconds');
                }

                cli_writeln('Adhoc task complete: ' . get_class($task));
                \core\task\manager::adhoc_task_complete($task);
            } catch (\Exception $e) {
                if ($DB && $DB->is_transaction_started()) {
                    cli_problem('Database transaction aborted automatically in ' . get_class($task));
                    $DB->force_transaction_rollback();
                }

                if (isset($predbqueries)) {
                    cli_writeln('... used ' . ($DB->perf_get_queries() - $predbqueries) . ' dbqueries');
                    cli_writeln('... used ' . (microtime(1) - $pretime) . ' seconds');
                }

                cli_writeln('Adhoc task failed: ' . get_class($task) . ',' . $e->getMessage());
                if ($CFG->debugdeveloper) {
                    if (!empty($e->debuginfo)) {
                        cli_writeln('Debug info:');
                        cli_writeln($e->debuginfo);
                    }

                    cli_writeln('Backtrace:');
                    cli_writeln(format_backtrace($e->getTrace(), true));
                }

                \core\task\manager::adhoc_task_failed($task);
            }

            get_mailer('close');
            unset($task);
        }

        set_config('lastran', time(), 'queue_cron');

        return true;
    }
}
