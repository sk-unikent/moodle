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
 * Base API for the recyclebin.
 */
class base {
    /**
     * Returns items in a given context.
     *
     * @param \context|int $contextorid The context object (or ID) of the desired items.
     */
    public static function get_context_items($contextorid) {
        global $DB;

        $contextid = is_object($contextorid) ? $contextorid->id : $contextorid;

        $items = array();

        $rs = $DB->get_recordset('backup_recyclebin', array('contextid' => $contextid));
        foreach ($rs as $record) {
            $items[] = item::from_record($record);
        }
        $rs->close();

        return $items;
    }
}
