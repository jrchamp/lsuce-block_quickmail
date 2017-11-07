<?php

namespace block_quickmail\messenger\factories;

use block_quickmail\messenger\message_body_parser;

class message_factory {

    public $userfrom;
    public $subject;
    public $fullmessagehtml;
    public $alternate_email;
    public $signature;
    public $custom_user_data_keys;
    public $validated_replyto;
    public $validated_replyto_name;
    public $message_body_parser;

    public function __construct($params = []) {
        $this->userfrom = $params['userfrom'];
        $this->subject = $params['subject'];
        $this->fullmessagehtml = $params['fullmessagehtml'];
        $this->alternate_email = $params['alternate_email'];
        $this->signature = $params['signature'];
        $this->custom_user_data_keys = $params['custom_user_data_keys'];
        $this->set_validated_replyto_data();
        $this->set_message_body_parser();
    }
    
    private function set_validated_replyto_data() {
        $this->validated_replyto = $this->get_validated_replyto();
        $this->validated_replyto_name = $this->get_validated_replyto_name();
    }

    private function set_message_body_parser() {
        $this->message_body_parser = new message_body_parser($this->fullmessagehtml, [], $this->custom_user_data_keys);
    }

    /**
     * Returns a validated reply-to email address for this message
     * 
     * @return string
     */
    private function get_validated_replyto() {
        // if no valid alternate email was passed, default to the user's default (we'll assume this is ok)
        if (empty($this->alternate_email)) {
            return $this->userfrom->email;
        }

        // get all allowed sending domains from system config
        $allowed_domains = $this->get_allowed_email_domains();

        // if this alternate email's domain is allowed, use it as replyto, otherwise, default to the user's email
        return in_array($this->alternate_email->get_domain(), $allowed_domains)
            ? $this->alternate_email->get('email')
            : $this->userfrom->email;
    }

    /**
     * Returns a validated reply-to user name for this message
     * 
     * @return string
     */
    private function get_validated_replyto_name() {
        // if a valid alternate email was passed, use it's name
        if ( ! empty($this->alternate_email)) {
            return $this->alternate_email->get_fullname();
        } else {
            return fullname($this->userfrom);
        }
    }

    /**
     * Returns an array of allowed email domains according to system config
     * 
     * @return array
     */
    private function get_allowed_email_domains() {
        $allowed = explode(' ', get_config(null, 'allowedemaildomains'));
        
        return array_map(function($email) {
            return ltrim($email, '.');
        }, $allowed);
    }

}