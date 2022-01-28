<?php
include_once('includes/enums/AcceptHeader.php');
include_once('includes/enums/RequestContentType.php');
include_once('includes/enums/RequestMethod.php');
include_once('includes/APICore.php');

class AlexaSkill {
    public APICore $APICore;
    public array $requestParams;

    public function __construct(){
        $this->APICore                              = new APICore();
    }

    private function handleRequest() {
        if ( $this->APICore->getRequestContentType() === RequestContentType::JSON ) {
            $json = file_get_contents( 'php://input' );
            $data = json_decode( $json, true );
            if( NULL === $json || "" === $json ){
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 400 Bad Request", true, 400 );
                die( '{"error":"No JSON data received in the request: <' . $json . '>"' );
            } else if ( json_last_error() !== JSON_ERROR_NONE ) {
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 400 Bad Request", true, 400 );
                die( '{"error":"Malformed JSON data received in the request: <' . $json . '>, ' . json_last_error_msg() . '"}' );
            } else {
                $this->requestParams = $data;
            }
        } else {
            switch( $this->APICore->getRequestMethod() ) {
                case RequestMethod::POST:
                    $this->requestParams = $_POST;
                    break;
                case RequestMethod::GET:
                    $this->requestParams = $_GET;
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
        if( $this->APICore->hasAcceptHeader() ) {
            if( $this->APICore->isAllowedAcceptHeader() ) {
                $this->APICore->setResponseContentType( $this->APICore->getAcceptHeader() );
            } else {
                //Requests from browser windows using the address bar will probably have an Accept header of text/html
                //In order to not be too drastic, let's treat text/html as though it were application/json
                $acceptHeaders = explode( ",", $this->APICore->getAcceptHeader() );
                if( in_array( 'text/html', $acceptHeaders ) || in_array( 'text/plain', $acceptHeaders ) || in_array( '*/*', $acceptHeaders ) ) {
                    $this->APICore->setResponseContentType( AcceptHeader::JSON );
                } else {
                    header( $_SERVER[ "SERVER_PROTOCOL" ]." 406 Not Acceptable", true, 406 );
                    $errorMessage = '{"error":"You are requesting a content type which this API cannot produce. Allowed Accept headers are ';
                    $errorMessage .= implode( ' and ', $this->APICore->getAllowedAcceptHeaders() );
                    $errorMessage .= ', but you have issued an request with an Accept header of ' . $this->APICore->getAcceptHeader() . '"}';
                    die( $errorMessage );
                }
            }
        } else {
            $this->APICore->setResponseContentType( $this->APICore->getAllowedAcceptHeaders()[ 0 ] );
        }
    }
    
    public function Init() : void {
        $this->handleRequest();
    }

}
