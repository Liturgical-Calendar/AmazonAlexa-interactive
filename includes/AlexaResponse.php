<?php
include_once( 'includes/OutputSpeech.php' );
include_once( 'includes/Card.php' );
include_once( 'includes/Reprompt.php' );

class AlexaResponse {
    public bool $shouldEndSession       = true;
    public ?OutputSpeech $outputSpeech  = null;
    public ?Card $card                  = null;
    public ?Reprompt $reprompt          = null;
    public ?object $directives          = null;
    public function __construct( bool $endSession = true ) {
        $this->shouldEndSession = $endSession;
        /*
        $response->response->directives = new stdClass();
        $response->response->directives->type = "InterfaceName.Directive";
        */
    }
}
