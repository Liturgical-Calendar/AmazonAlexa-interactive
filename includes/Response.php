<?php
include_once('includes/SessionAttributes.php');
include_once('includes/AlexaResponse.php');

class Response {
    public string $version = "1.0";
    public ?SessionAttributes $sessionAttributes = null;
    public ?AlexaResponse $response = null;

    public function __construct() {
        $sessionAttributes = new SessionAttributes();
    }
}
