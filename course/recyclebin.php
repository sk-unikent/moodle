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
 * Allows users to restore deleted activities.
 *
 * @package course
 * @copyright 2016 Skylar Kelty
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("../config.php");
require_once($CFG->libdir . '/tablelib.php');

$id = required_param('id', PARAM_INT);
$action = optional_param('action', null, PARAM_ALPHA);

$PAGE->set_url(new moodle_url('/course/recyclebin.php', array(
    'id' => $id
)));

$course = $DB->get_record('course', array('id' => $id));
$context = context_course::instance($course->id);
require_login($course);
require_capability('moodle/course:update', $context);

if ($action) {
    $itemid = required_param('itemid', PARAM_INT);
    $item = \core\recyclebin\item::get($itemid, \MUST_EXIST);

    switch ($action) {
        case 'restore':
            if ($item->restore()) {
                redirect($PAGE->url, get_string('recyclebinrestored', '', $item->name), 2);
            }
        break;

        case 'delete':
            if ($item->delete()) {
                redirect($PAGE->url, get_string('recyclebindeleted', '', $item->name), 2);
            }
        break;
    }
}

$PAGE->set_context(context_course::instance($course->id));
$PAGE->set_pagelayout('course');
$PAGE->set_title(get_string('recyclebin'));
$PAGE->set_heading($PAGE->title);

$renderer = $PAGE->get_renderer('core', 'course');

echo $OUTPUT->header();
echo $OUTPUT->heading($PAGE->heading);

$modules = $DB->get_records('modules');
$items = core\recyclebin\base::get_context_items($context);
if (!empty($items)) {
    // Define a table.
    $table = new \flexible_table('recyclebin');
    $table->define_columns(array('activity', 'date', 'restore', 'delete'));
    $table->define_headers(array(
        get_string('activity'),
        get_string('datedeleted'),
        get_string('restore'),
        get_string('delete')
    ));
    $table->define_baseurl($PAGE->url);
    $table->set_attribute('id', 'recycle-bin-table');
    $table->setup();

    foreach ($items as $item) {
        $row = array();

        // Build item name.
        $name = $item->name;
        if (isset($modules[$item->module])) {
            $mod = $modules[$item->module];
            $name = \html_writer::empty_tag('img', array(
                'src' => $OUTPUT->pix_url('icon', $mod->name),
                'class' => 'icon',
                'alt' => get_string('modulename', $mod->name)
            )) . " {$item->name}";
        }

        $row[] = $name;
        $row[] = userdate($item->deleted);

        $restoreurl = new \moodle_url($PAGE->url, array(
            'id' => $id,
            'itemid' => $item->id,
            'action' => 'restore',
            'sesskey' => sesskey()
        ));

        $row[] = $OUTPUT->action_icon($restoreurl, new pix_icon('t/restore', get_string('restore'), '', array(
            'class' => 'iconsmall'
        )));

        $delete = new \moodle_url($PAGE->url, array(
            'id' => $id,
            'itemid' => $item->id,
            'action' => 'delete',
            'sesskey' => sesskey()
        ));
        $row[] = $OUTPUT->action_icon($delete, new pix_icon('t/delete',
                    get_string('delete'), '', array('class' => 'iconsmall')), null,
                    array('class' => 'action-icon recycle-bin-delete'));

        $table->add_data($row);
    }

    // Display the table now.
    $table->finish_output();
} else {
    echo $OUTPUT->box(get_string('recyclebinempty'));
}

echo $OUTPUT->footer();

