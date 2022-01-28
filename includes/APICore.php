<?php
include_once('includes/enums/AcceptHeader.php');
include_once('includes/enums/RequestContentType.php');
include_once('includes/enums/RequestMethod.php');

class APICore {

    private array $AllowedAcceptHeaders;
    private array $AllowedRequestMethods;
    private array $AllowedRequestContentTypes;
    public array $RequestHeaders                = [];
    private ?string $JsonEncodedRequestHeaders  = null;
    private ?string $RequestContentType         = null;
    private ?string $ResponseContentType        = null;

    public function __construct(){
        $this->AllowedAcceptHeaders             = AcceptHeader::$values;
        $this->AllowedRequestMethods            = RequestMethod::$values;
        $this->AllowedRequestContentTypes       = RequestContentType::$values;
        $this->RequestHeaders                   = getallheaders();
    }

    private function setAccessControlAllowMethods() {
        if ( isset( $_SERVER[ 'REQUEST_METHOD' ] ) ) {
            if ( isset( $_SERVER[ 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' ] ) ) {
                header( "Access-Control-Allow-Methods: " . implode(',', $this->AllowedRequestMethods) );
            }
            if ( isset( $_SERVER[ 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' ] ) ) {
                header( "Access-Control-Allow-Headers: {$_SERVER[ 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' ]}" );
            }
        }
    }

    public function validateAcceptHeader() {
        if( $this->hasAcceptHeader() ) {
            if( $this->isAllowedAcceptHeader() ) {
                $acceptHeaders = explode( ",", $this->RequestHeaders[ "Accept" ] );
                $firstAcceptHeader = explode( ";", $acceptHeaders[0] );
                $this->ResponseContentType = $firstAcceptHeader[0];
            } else {
                $this->sendHeaderNotAcceptable();
            }
        } else {
            $this->ResponseContentType = $this->AllowedAcceptHeaders[0];
        }
        $this->setResponseContentTypeHeader();
    }

    private function validateRequestContentType() {
        if( isset( $_SERVER[ 'CONTENT_TYPE' ] ) && $_SERVER[ 'CONTENT_TYPE' ] !== '' ) {
            $contentType = explode( ';', $_SERVER[ 'CONTENT_TYPE' ] );
            if( !in_array( $contentType[0], $this->AllowedRequestContentTypes ) ){
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 415 Unsupported Media Type", true, 415 );
                die( '{"error":"You seem to be forming a strange kind of request? Allowed Content Types are '.implode( ' and ', $this->AllowedRequestContentTypes ).', but your Content Type was '.$_SERVER[ 'CONTENT_TYPE' ].'"}' );
            } else {
                $this->RequestContentType = $contentType[0];
            }
        } else {
            $this->RequestContentType = $this->AllowedRequestContentTypes[0];
        }
    }

    private function sendHeaderNotAcceptable() : void {
        header( $_SERVER[ "SERVER_PROTOCOL" ]." 406 Not Acceptable", true, 406 );
        $errorMessage = '{"error":"You are requesting a content type which this API cannot produce. Allowed Accept headers are ';
        $errorMessage .= implode( ' and ', $this->AllowedAcceptHeaders );
        $errorMessage .= ', but you have issued an request with an Accept header of ' . $this->RequestHeaders[ "Accept" ] . '"}';
        die( $errorMessage );
    }

    public function setAllowedAcceptHeaders( array $acceptHeaders ) : void {
        $this->AllowedAcceptHeaders = array_values( array_intersect( AcceptHeader::$values, $acceptHeaders ) );
    }

    public function setAllowedRequestMethods( array $requestMethods ) : void {
        $this->AllowedRequestMethods = array_values( array_intersect( RequestMethod::$values, $requestMethods ) );
    }

    public function setAllowedRequestContentTypes( array $requestContentTypes ) : void {
        $this->AllowedRequestContentTypes = array_values( array_intersect( RequestContentType::$values, $requestContentTypes ) );
    }

    public function setResponseContentType( string $responseContentType ) : void {
        $this->ResponseContentType = $responseContentType;
    }

    public function setResponseContentTypeHeader() : void {
        header( "Content-Type: {$this->ResponseContentType}; charset=utf-8" );
    }

    public function getAllowedAcceptHeaders() : array {
        return $this->AllowedAcceptHeaders;
    }

    public function getAllowedRequestContentTypes() : array {
        return $this->AllowedRequestContentTypes;
    }

    public function getAcceptHeader() : string {
        return $this->RequestHeaders[ "Accept" ];
    }

    public function hasAcceptHeader() : bool {
        return isset( $this->RequestHeaders[ "Accept" ] );
    }

    public function isAllowedAcceptHeader() : bool {
        $acceptHeaders = explode( ",", $this->RequestHeaders[ "Accept" ] );
        $firstAcceptHeader = explode( ";", $acceptHeaders[0] );
        return in_array( $firstAcceptHeader[0], $this->AllowedAcceptHeaders );
    }

    public function getAllowedRequestMethods() : array {
        return $this->AllowedRequestMethods;
    }

    public function getRequestMethod() : string {
        return strtoupper( $_SERVER[ 'REQUEST_METHOD' ] );
    }

    public function getRequestHeaders() : array {
        return $this->RequestHeaders;
    }

    public function getJsonEncodedRequestHeaders() : string {
        return $this->JsonEncodedRequestHeaders;
    }

    public function getRequestContentType() : ?string {
        return $this->RequestContentType;
    }

    public function retrieveRequestParamsFromJsonBody( ?string $body = null ) : ?object {
        if( $body === null ) { 
            $body = file_get_contents( 'php://input' );
        }
        if( "" === $body ){
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 400 Bad Request", true, 400 );
            die( '{"error":"No JSON data received in the request"' );
        }
        else {
            $data = json_decode( $body );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 400 Bad Request", true, 400 );
                die( '{"error":"Malformed JSON data received in the request: <' . $body . '>, ' . json_last_error_msg() . '"}' );
            } else {
                return $data;
            }
        }
        return null;
    }

    public function Init() {
        header( 'Access-Control-Allow-Origin: *' );
        $this->setAccessControlAllowMethods();
        $this->validateAcceptHeader();
        $this->validateRequestContentType();
    }

}
