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
 * @package    block_quickmail
 * @copyright  2008 onwards Louisiana State University
 * @copyright  2008 onwards Chad Mazilly, Robert Russo, Jason Peak, Dave Elliott, Adam Zapletal, Philip Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once 'lib.php';

$page_url = '/blocks/quickmail/queued.php';

$page_params = [
    'courseid' => optional_param('courseid', 0, PARAM_INT),
    'sort' => optional_param('sort', 'scheduled', PARAM_TEXT), // (field name)
    'dir' => optional_param('dir', 'asc', PARAM_TEXT), // asc|desc
    'page' => optional_param('page', 1, PARAM_INT),
    'per_page' => 10, // adjust as necessary, maybe turn into real param?
];

////////////////////////////////////////
/// AUTHENTICATION
////////////////////////////////////////

require_login();

// if we're scoping to a specific course
if ($page_params['courseid']) {
    // check that the user can message in this course
    block_quickmail_plugin::require_user_has_course_message_access($USER, $page_params['courseid']);
}

$user_context = context_user::instance($USER->id);
$PAGE->set_context($user_context);
$PAGE->set_url(new moodle_url($page_url, $page_params));

////////////////////////////////////////
/// CONSTRUCT PAGE
////////////////////////////////////////

$PAGE->set_pagetype('block-quickmail');
$PAGE->set_pagelayout('standard');
$PAGE->set_title(block_quickmail_string::get('pluginname') . ': ' . block_quickmail_string::get('queued'));
$PAGE->navbar->add(block_quickmail_string::get('pluginname'));
$PAGE->navbar->add(block_quickmail_string::get('queued'));
$PAGE->set_heading(block_quickmail_string::get('pluginname') . ': ' . block_quickmail_string::get('queued'));
$PAGE->requires->css(new moodle_url($CFG->wwwroot . '/blocks/quickmail/style.css'));
$PAGE->requires->js_call_amd('block_quickmail/queued-index', 'init');

$renderer = $PAGE->get_renderer('block_quickmail');

////////////////////////////////////////
/// INSTANTIATE FORM
////////////////////////////////////////

$manage_queued_form = \block_quickmail\forms\manage_queued_form::make(
    $user_context, 
    $USER, 
    $page_params['courseid']
);

////////////////////////////////////////
/// HANDLE REQUEST
////////////////////////////////////////

$request = block_quickmail_request::for_route('queued')->with_form($manage_queued_form);

////////////////////////////////////////
/// HANDLE REQUEST
////////////////////////////////////////


try {
    // UNQUEUE
    if ($request->to_unqueue_message()) {
        // attempt to fetch the message to unqueue
        if ( ! $message = block_quickmail\repos\queued_repo::find_for_user_or_null($request->data->unqueue_message_id, $USER->id)) {
            // redirect and notify of error
            $request->redirect_as_error(block_quickmail_string::get('queued_no_record'), $page_url, ['courseid' => $page_params['courseid']]);
        }

        // attempt to unqueue
        $message->unqueue();
    
    // SEND NOW
    } else if ($request->to_send_message_now()) {
        // attempt to fetch the message to send now
        if ( ! $message = block_quickmail\repos\queued_repo::find_for_user_or_null($request->data->send_now_message_id, $USER->id)) {
            // redirect and notify of error
            $request->redirect_as_error(block_quickmail_string::get('queued_no_record'), $page_url, ['courseid' => $page_params['courseid']]);
        }

        // attempt to force the queued message to be sent now
        $message->changed_queued_to_now();
    }
} catch (\block_quickmail\exceptions\validation_exception $e) {
    $manage_queued_form->set_error_exception($e);
} catch (\block_quickmail\exceptions\critical_exception $e) {
    print_error('critical_error', 'block_quickmail');
}

// get all (queued) messages belonging to this user and course
$queued_messages = block_quickmail\repos\queued_repo::get_for_user($USER->id, $page_params['courseid'], [
    'sort' => $page_params['sort'], 
    'dir' => $page_params['dir'],
    'paginate' => true,
    'page' => $page_params['page'], 
    'per_page' => $page_params['per_page'],
    'uri' => $_SERVER['REQUEST_URI']
]);

$rendered_queued_message_index = $renderer->queued_message_index_component([
    'queued_messages' => $queued_messages->data,
    'queued_pagination' => $queued_messages->pagination,
    'user' => $USER,
    'course_id' => $page_params['courseid'],
    'sort_by' => $page_params['sort'],
    'sort_dir' => $page_params['dir'],
]);

$rendered_manage_queued_form = $renderer->manage_queued_component([
    'manage_queued_form' => $manage_queued_form,
]);

echo $OUTPUT->header();
$manage_queued_form->render_error_notification();
echo $rendered_queued_message_index;
echo $rendered_manage_queued_form;
echo $OUTPUT->footer();