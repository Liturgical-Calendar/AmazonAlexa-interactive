<?php
include_once('includes/enums/AcceptHeader.php');
include_once('includes/enums/RequestContentType.php');
include_once('includes/enums/RequestMethod.php');
include_once('includes/APICore.php');

class AlexaSkill {
    public APICore $APICore;
    public ?object $requestParams = null;
    public string $rawRequestData = '';

    public function __construct(){
        $this->APICore = new APICore();
    }

    private function handleRequest() {
        switch( $this->APICore->getRequestMethod() ) {
            case RequestMethod::GET:
            case RequestMethod::POST:
                if ( $this->APICore->getRequestContentType() === RequestContentType::JSON ) {
                    $this->rawRequestData = file_get_contents( 'php://input' );
                    $this->requestParams = $this->APICore->retrieveRequestParamsFromJsonBody( $this->rawRequestData );
                } else {
                    $this->rawRequestData = "request content type was not json: " . $this->APICore->getRequestContentType();
                }
                break;
            case RequestMethod::OPTIONS:
                //continue
                break;
            default:
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 405 Method Not Allowed", true, 405 );
                $errorMessage = '{"error":"You seem to be forming a strange kind of request? Allowed Request Methods are ';
                $errorMessage .= implode( ' and ', $this->APICore->getAllowedRequestMethods() );
                $errorMessage .= ', but your Request Method was ' . $this->APICore->getRequestMethod() . '"}';
                die( $errorMessage );
        }
    }

    public function Init() : void {
        $this->APICore->Init();
        $this->handleRequest();
    }

}
