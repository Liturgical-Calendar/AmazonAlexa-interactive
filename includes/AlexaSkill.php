<?php
include_once( 'includes/enums/AcceptHeader.php' );
include_once( 'includes/enums/RequestContentType.php' );
include_once( 'includes/enums/RequestMethod.php' );
include_once( 'includes/APICore.php' );
include_once( 'includes/Response.php' );
include_once( 'includes/AlexaResponse.php' );
include_once( 'includes/OutputSpeech.php' );
include_once( 'includes/Card.php' );

include_once( 'config.php' );

class AlexaSkill {
    const METADATA_URL  = 'https://litcal.johnromanodorazio.com/api/v3/LitCalMetadata.php';
    const LITCAL_URL    = 'https://litcal.johnromanodorazio.com/api/v3/LitCalEngine.php';

    private APICore $APICore;
    private string $rawRequestData  = '';
    private ?object $requestParams  = null;
    private ?string $sessionId      = null;
    private ?string $userId         = null;
    private Response $output;
    private array $log              = [];
    private array $LitCalData       = [];
    private string $Locale          = "en";

    public function __construct(){
        $this->APICore = new APICore();
        $this->output = new Response();
        $timestampRequestReceived = new DateTime('NOW');
        $this->log[] = $timestampRequestReceived->format('r');
    }

    private function readRequest() {
        switch( $this->APICore->getRequestMethod() ) {
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

    private function verifyApplicationId() {
        if( property_exists( $this->requestParams, 'session' ) ) {
            if( $this->requestParams->session->application->applicationId !== APPLICATION_ID ) {
                die("{\"ERROR\":\"I'm a teapot\"}");
            } else {
                $this->sessionId  = $this->requestParams->session->sessionId;
                $this->userId     = $this->requestParams->session->user->userId;
            }
        }
        if( property_exists( $this->requestParams, 'context' ) ) {
            if( $this->requestParams->context->System->application->applicationId !== APPLICATION_ID ) {
                die("{\"ERROR\":\"I'm a teapot\"}");
            }
            if( $this->userId === null ) {
                $this->userId = $this->requestParams->context->System->user->userId;
            }
        }
        $this->log[] = "sessionId:\t{$this->sessionId}";
        $this->log[] = "userId:\t{$this->userId}";
    }

    private function setLocale() : void {
        if( property_exists( $this->requestParams, 'request' ) ) {
            $request = $this->requestParams->request;
            $this->Locale = str_replace( '-', '_', $request->locale );
            $localeArray = [
                $this->Locale . '.utf8',
                $this->Locale . '.UTF-8',
                $this->Locale,
                explode('_', $this->Locale)[0]
            ];
            setlocale( LC_ALL, $localeArray );
            bindtextdomain("catholicliturgy", "i18n");
            textdomain("catholicliturgy");
        }
    }

    private function handleRequest() {
        $this->verifyApplicationId();
        if( property_exists( $this->requestParams, 'request' ) ) {
            $request = $this->requestParams->request;

            $this->log[] = "requestType:\t{$request->type}";

            switch( $request->type ) {
                case 'LaunchRequest':
                    //file_put_contents('requests.log', implode(PHP_EOL, $log) . PHP_EOL . PHP_EOL, FILE_APPEND);
                    $defaultReponse = [
                        _( "Hello from Catholic Liturgy!" ),
                        _( "Today is . I can also give all sorts of information about the Liturgical year, and interesting Liturgical facts. Try me!" )
                    ];

                    $alexaResponse = new AlexaResponse( false );
                    $alexaResponse->outputSpeech = new OutputSpeech( "PlainText", implode(' ', $defaultReponse) );
                    //$alexaResponse->reprompt = new Reprompt();
                    //$alexaResponse->reprompt->outputSpeech = new OutputSpeech( "PlainText", "I'm not sure I understand what you are asking. Did you want the Liturgy of the day?" );
                    $alexaResponse->card = new Card( "Standard", $defaultReponse[0], $defaultReponse[1] );
                    $this->output->response = $alexaResponse;
                    break;
                case 'IntentRequest':
                    $intent = $request->intent;
                    switch( $intent->name ) {
                        case 'CatholicLiturgyIntent':
                            $slots = $intent->slots;

                            $year       = property_exists( $slots->date, 'value' ) ? intval( $slots->date->value ) : null;
                            $rank       = property_exists( $slots->rank, 'value' ) ? $slots->rank->value : null;
                            $festivity  = property_exists( $slots->festivity, 'value' ) ? $slots->festivity->value : null;

                            $queryArray = [];
                            $queryArray[ "locale" ] = $this->Locale;
                            if( $year !== null ) {
                                $queryArray[ "year" ] = $year;
                            }
                            $this->sendAPIRequest( $queryArray );

                            if( count($this->LitCalData) ) {

                            }

                            $alexaResponse = new AlexaResponse( false );
                            $alexaResponse->outputSpeech = new OutputSpeech( "PlainText", implode(' ', $defaultReponse) );
                            $alexaResponse->card = new Card( "Standard", $defaultReponse[0], $defaultReponse[1] );
        
                            $this->output->response = $alexaResponse;
        
                            break;
                        case 'AMAZON.FallbackIntent':
                            $alexaResponse = new AlexaResponse( false );
                            $message = _( "I'm not sure I understand what you are asking. Did you want the Liturgy of the day?" );
                            $alexaResponse->outputSpeech = new OutputSpeech( "PlainText", $message );
                            $alexaResponse->card = new Card( "Standard","Confused", $message );
                            $this->output->response = $alexaResponse;
                            break;
                    }
                    break;
                case 'SessionEndedRequest':
                    break;
                }
        }
    }

    private function sendAPIRequest( array $queryArray ) {
        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_URL, self::LITCAL_URL );
        curl_setopt( $ch, CURLOPT_POST, 1 );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $queryArray ) );
        $result = curl_exec( $ch );
        if ( curl_errno( $ch ) ) {
            die( "Could not send request. Curl error: " . curl_error( $ch ) );
        } else {
            $resultStatus = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            if ( $resultStatus != 200 ) {
                die( "Request failed. HTTP status code: " . $resultStatus );
            } else {
                $this->LitCalData = json_decode( $result, true );
            }
        }
        curl_close( $ch );
    }

    private function outputResponse() : void {
        $outputResponse = json_encode( $this->output );
        $this->log[] = "outputResponse:\t{$outputResponse}";
        echo $outputResponse;
    }

    public function Init() : void {
        $this->APICore->Init();
        $this->readRequest();
        $this->setLocale();
        $this->handleRequest();
        $this->outputResponse();
        file_put_contents('requests.log', implode(PHP_EOL, $this->log) . PHP_EOL . PHP_EOL, FILE_APPEND);
    }

}
