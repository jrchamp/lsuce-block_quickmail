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
 * @copyright  2008-2017 Louisiana State University
 * @copyright  2008-2017 Adam Zapletal, Chad Mazilly, Philip Cali, Robert Russo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_quickmail\messenger;

use block_quickmail_plugin;
use block_quickmail\requests\compose_message_request;
use block_quickmail\messenger\validators\send_validator;
use block_quickmail\messenger\exceptions\messenger_authentication_exception;
use block_quickmail\messenger\exceptions\messenger_validation_exception;
use block_quickmail\messenger\exceptions\messenger_critical_exception;
use block_quickmail\persistents\signature;
use block_quickmail\persistents\alternate_email;
use core_user;

class messenger {

    public $context;
    public $user;
    public $message_data;
    public $course;
    public $draft_message;
    public $message_scope;  // course|system
    public $signature;
    public $alternate_email;
    public $config;
    public $validation_errors;
    public $custom_user_data_keys;
    
    // message_data
        // public $subject;
        // public $additional_emails;
        // public $message;
        // public $signature_id;
        // public $output_channel;
        // public $receipt;
        // public $alternate_email_id;

    /**
     * Construct the messenger service
     * 
     * @param context                                    $context
     * @param mdl_user                                   $user            the sending user
     * @param object                                     $message_data    all posted message data
     * @param mdl_course|null                            $course          moodle course that this message is being sent in
     * @param block_quickmail\persistents\message|null   $draft_message   
     */
    public function __construct($context, $user, $message_data, $course = null, $draft_message = null) {
        $this->context = $context;
        $this->user = $user;
        $this->message_data = $message_data;
        $this->course = $course;
        $this->draft_message = $draft_message;
        $this->validation_errors = [];
        $this->custom_user_data_keys = [];
        $this->set_message_scope();
        $this->set_config();
        $this->set_signature();
        $this->set_alternate_email();
    }

    /**
     * Sets the "message scope" of the messenger (course or system)
     *
     * @return void
     */
    private function set_message_scope() {
        $this->message_scope = str_replace('context_', '', get_class($this->context));
    }

    /**
     * Sets the config array for this messenger instance (course-specific, or global) depending on scope
     */
    private function set_config() {
        $this->config = block_quickmail_plugin::_c('', $this->course ? $this->course->id : 0);
    }

    /*
     * Sets the user signature (if any)
     */
    private function set_signature() {
        $this->signature = signature::find_or_null($this->message_data->signature_id);
    }

    /*
     * Sets the user alternate email (if any)
     */
    private function set_alternate_email() {
        $this->alternate_email = alternate_email::find_or_null($this->message_data->alternate_email_id);
    }

    /**
     * Sends a message given a request and returns a response
     *
     * Note: this could either be a fresh new message OR a loaded draft
     * 
     * @param  compose_message_request  $compose_message_request
     * @return object
     * @throws messenger_authentication_exception
     * @throws messenger_validation_exception
     * @throws messenger_critical_exception
     */
    public static function send_by_compose_request(compose_message_request $compose_message_request)
    {
        // for now we'll just send a "fresh one"
        $messenger = new self(
            $compose_message_request->form->context,
            $compose_message_request->form->user,
            $compose_message_request->get_request_data_object(),
            $compose_message_request->form->course,
            $compose_message_request->form->draft_message
        );

        // if this is a draft and has already been sent, throw exception
        if ($messenger->is_draft_send() && empty($messenger->draft_message->get('sent_at'))) {
            $messenger->throw_validation_exception('This message has already been sent.');
        }

        // reconcile recipients
        $recipient_user_ids = $messenger->message_data->mailto_ids;

        // get all recip user ids from form

        // 


        $messenger_response = $messenger->send();

        return $messenger_response;
    }

    /**
     * Attempts to validate, authorize and send the message
     * 
     * @return [type] [description]
     * @throws messenger_authentication_exception
     * @throws messenger_validation_exception
     * @throws messenger_critical_exception
     */
    private function send() {

        // first, make sure this user can send within this context
        if ( ! $this->authorize_send()) {
            $this->throw_authentication_exception();
        }

        // second, validate all form inputs
        if ( ! $this->validate_send()) {
            $this->throw_validation_exception();
        }

        // $this->is_draft_send()

        // $this->

        // // get recipient user ids
        // $recipient_user_ids = $this->message_data->mailto_ids;

        // construct an appropriate message factory based on the output channel
        $message_factory = $this->make_message_factory();

        // iterate through each recipient by user_id
        foreach ($recipient_user_ids as $user_id) {
            
            // TODO: do something with this!
            if ( ! $user = core_user::get_user($user_id)) {
                // not an active or real user...
                // log this somehow
                continue;
            }

            // send the customized message to the user by appropriate channel
            // note (if channel='message') this returns the mdl_message->id
            $factory_response = $message_factory->send_message($user);

            // convert response into an int 
            $m_id = $this->convert_factory_response_to_id($factory_response);
        }

        return $this;
    }

    private function make_message_factory() {
        $factory_class = $this->get_message_factory_class_name();

        return $factory_class::make([
            'userfrom' => $this->user,
            'subject' => $this->format_message_subject(),
            'fullmessagehtml' => $this->message_data->message,
            'alternate_email' => $this->alternate_email,
            'signature' => $this->signature,
            'custom_user_data_keys' => $this->custom_user_data_keys
        ]);
    }

    /**
     * Instantiates a message factory class based on the selected output channel
     * 
     * @return message_messenger_interface
     */
    private function get_message_factory_class_name() {
        return '\block_quickmail\messenger\factories\\' . $this->message_data->output_channel . '_message_factory';
    }

    /**
     * Returns the message subject formatted with any prepend options
     * 
     * @return string
     */
    public function format_message_subject() {
        // get the prepend_class setting
        $prepend = $this->config['prepend_class'];

        // if setting is valid and exists on the course, format subject, otherwise default to subject
        return ! empty($prepend) && ! empty($this->course->$prepend)
            ? '[' . $this->course->$prepend . '] ' . $this->message_data->subject
            : $this->message_data->subject;
    }

    
    /**
     * Converts the given response into an integer
     *
     * Note: for "message" output, this will be mdl_message->id, for "email" output this will always be zero
     * 
     * @param  mixed  $factory_response   response from sending a moodle message or an email
     * @return int
     */
    private function convert_factory_response_to_id($factory_response) {
        return is_bool($factory_response) === false ? $factory_response : 0;
    }

    /**
     * Reports whether or not the sender is authorized to send a message
     * 
     * @return bool
     */
    private function authorize_send() {
        return has_capability('block/quickmail:cansend', $this->context) || ! empty($this->config['allowstudents']);
    }

    /**
     * Reports whether or not this message is valid to be sent, if not, collects error messages
     *
     * Note: this also caches the "custom_user_data_keys" for later use!!!
     * 
     * @return bool
     */
    private function validate_send() {
        // instantiate a new "send" validator
        $validator = new send_validator($this);

        // perform the validation
        $validator->validate();

        // grab the errors, and custom user data keys found
        $this->validation_errors = $validator->errors;
        $this->custom_user_data_keys = $validator->custom_user_data_keys;

        // return success status boolean
        return count($this->validation_errors) ? false : true;
    }

    /**
     * Reports whether or not this message is a draft that is being sent
     * 
     * @return bool
     */
    private function is_draft_send() {
        return ! empty($this->draft_message);
    }

    /**
     * Throws a authentication exception with the given message
     * 
     * @param  string $message
     * @return void
     * @throws messenger_authentication_exception
     */
    private function throw_authentication_exception($message = 'Messenger authentication exception') {
        throw new messenger_authentication_exception($message);
    }

    /**
     * Throws a validation exception with the given message
     * 
     * @param  string $message
     * @return void
     * @throws messenger_validation_exception
     */
    private function throw_validation_exception($message = 'Messenger validation exception') {
        throw new messenger_validation_exception($message, $this->validation_errors);
    }

    /**
     * Throws a critical exception with the given message
     * 
     * @param  string $message
     * @return void
     * @throws messenger_critical_exception
     */
    private function throw_critical_exception($message = 'Messenger critical exception') {
        throw new messenger_critical_exception($message);
    }

}