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
 * A recyclebin item.
 */
class item {
    /** @var int Item ID. */
    public $id;

    /** @var \stdClass Context of this item. */
    public $context;

    /** @var array Decoded data. */
    protected $data;

    /** @var int Deleted date. */
    public $deleted;

    /**
     * Constructor.
     *
     * @param int $id Item ID.
     * @param \stdClass $context Context of this item.
     * @param array $data Decoded data.
     * @param int $deleted Deleted date.
     */
    private function __construct($id, $context, $data, $deleted) {
        $this->id = $id;
        $this->context = $context;
        $this->data = (array)$data;
        $this->deleted = $deleted;
    }

    /**
     * Grab an item object from a database record.
     *
     * @param \stdClass $record The database record from backup_recyclebin.
     */
    public static function from_record(\stdClass $record) {
        $context = \context::instance_by_id($record->contextid);

        switch ($context->contextlevel) {
            case \CONTEXT_COURSE:
            return new module($record->id, $context, json_decode($record->data), $record->deleted);

            default:
            return null;
        }
    }

    /**
     * Get an item by ID.
     *
     * @param int $id The item ID.
     * @param int $strictness IGNORE_MISSING means compatible mode, false returned if record not found, debug message if more found;
     *                        MUST_EXIST means we will throw an exception if no record or multiple records found.
     */
    public static function get($id, $strictness = \IGNORE_MISSING) {
        global $DB;

        $record = $DB->get_record('backup_recyclebin', array('id' => $id), '*', $strictness);
        if ($record) {
            return static::from_record($record);
        }

        return null;
    }

    /**
     * Magic get method.
     */
    public function __get($name) {
        return $this->data[$name];
    }

    /**
     * Magic isset method.
     */
    public function __isset($name) {
        return isset($this->data[$name]);
    }

    /**
     * Delete an item from the recycle bin.
     *
     * @param stdClass $item The item database record
     * @throws \coding_exception
     */
    public function delete() {
        global $CFG, $DB;

        // Delete the file.
        unlink($CFG->dataroot . '/recyclebin/' . $this->id);

        // Delete the record.
        $DB->delete_records('backup_recyclebin', array(
            'id' => $this->id
        ));
    }
}
