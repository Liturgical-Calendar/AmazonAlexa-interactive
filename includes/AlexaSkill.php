<?php
include_once( 'includes/enums/AcceptHeader.php' );
include_once( 'includes/enums/RequestContentType.php' );
include_once( 'includes/enums/RequestMethod.php' );
include_once( 'includes/enums/LitCommon.php' );
include_once( 'includes/enums/LitGrade.php' );
include_once( 'includes/enums/LitLocale.php' );
include_once( 'includes/APICore.php' );
include_once( 'includes/Response.php' );
include_once( 'includes/AlexaResponse.php' );
include_once( 'includes/OutputSpeech.php' );
include_once( 'includes/Card.php' );
include_once( 'includes/Festivity.php' );

include_once( 'config.php' );

class AlexaSkill {
    const METADATA_URL  = 'https://litcal.johnromanodorazio.com/api/v3/LitCalMetadata.php';
    const LITCAL_URL    = 'https://litcal.johnromanodorazio.com/api/v3/LitCalEngine.php';

    public APICore $APICore;
    private LitCommon $LitCommon;
    private LitGrade $LitGrade;
    private string $rawRequestData  = '';
    private ?object $requestParams  = null;
    private ?string $sessionId      = null;
    private ?string $userId         = null;
    private Response $output;
    private array $log              = [];
    private array $LitCalData       = [];
    private array $LitCalFeed       = [];
    private IntlDateFormatter $monthDayFmt;
    private string $Locale          = "en_US";

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
        }
        $localeArray = [
            $this->Locale . '.utf8',
            $this->Locale . '.UTF-8',
            $this->Locale,
            explode('_', $this->Locale)[0]
        ];
        setlocale( LC_ALL, $localeArray );
        bindtextdomain("catholicliturgy", "i18n");
        textdomain("catholicliturgy");
        $this->monthDayFmt  = IntlDateFormatter::create($this->Locale, IntlDateFormatter::FULL, IntlDateFormatter::FULL, 'UTC', IntlDateFormatter::GREGORIAN, 'd MMMM' );
        $locale = strtoupper( explode('_', $this->Locale)[0] );
        $this->LitCommon    = new LitCommon( $locale );
        $this->LitGrade     = new LitGrade( $locale );
    }

    private function handleRequest() {
        $this->verifyApplicationId();
        if( property_exists( $this->requestParams, 'request' ) ) {
            $request = $this->requestParams->request;

            $this->log[] = "requestType:\t{$request->type}";

            switch( $request->type ) {
                case 'LaunchRequest':
                    $queryArray = [];
                    $queryArray[ "locale" ] = strtoupper( explode('_', $this->Locale)[0] );
                    $this->sendAPIRequest( $queryArray );
                    [ $titleText, $mainText ] = $this->filterEventsToday();
                    //file_put_contents('requests.log', implode(PHP_EOL, $log) . PHP_EOL . PHP_EOL, FILE_APPEND);
                    $defaultReponse = [
                        _( "Hello from Catholic Liturgy!" ),
                        $mainText . '' . _( "If you'd like any information about the Liturgical year, just ask!" )
                    ];

                    $alexaResponse = new AlexaResponse( false );
                    $alexaResponse->outputSpeech = new OutputSpeech( "PlainText", implode(' ', $defaultReponse) );
                    //$alexaResponse->reprompt = new Reprompt();
                    //$alexaResponse->reprompt->outputSpeech = new OutputSpeech( "PlainText", "I'm not sure I understand what you are asking. Did you want the Liturgy of the day?" );
                    $alexaResponse->card = new Card( "Standard", $titleText, $mainText );
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
                            $queryArray[ "locale" ] = strtoupper( explode('_', $this->Locale)[0] );
                            if( $year !== null ) {
                                $queryArray[ "year" ] = $year;
                            }
                            $this->sendAPIRequest( $queryArray );
                            //[ $titleText, $mainTest ] = $this->filterEventsToday();

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

    private function filterEventsToday() : array {
        $titleText = '';
        $mainText = [];
        $dateTimeToday = ( new DateTime( 'now' ) )->format( "Y-m-d" ) . " 00:00:00";
        $dateToday = DateTime::createFromFormat( 'Y-m-d H:i:s', $dateTimeToday, new DateTimeZone( 'UTC' ) );
        $dateTodayTimestamp = $dateToday->format( "U" );
        $dateToday->add( new DateInterval( 'PT10M' ) );
        if( isset( $this->LitCalData["LitCal"] ) ) {
            $LitCal = $this->LitCalData["LitCal"];
            $idx = 0;
            foreach ( $LitCal as $key => $value ) {
                //fwrite( $logFile, "Processing litcal event $key..." . "\n" );
                if( $LitCal[$key]["date"] === $dateTodayTimestamp ) {
                    // retransform each entry from an associative array to a Festivity class object
                    $festivity = new Festivity( $LitCal[$key] );
                    $mainText[] = $this->prepareMainText( $festivity, $idx );
                    //fwrite( $logFile, "mainText = $mainText" . "\n" );
                    if( $idx === 0 ) {
                        $titleText = _( "Liturgy of the Day" ) . " ";
                        if( strtoupper( explode('_', $this->Locale)[0] ) === LitLocale::ENGLISH ) {
                            $titleText .= $festivity->date->format( 'F jS' );
                        } else {
                            $titleText .= $this->monthDayFmt->format( $festivity->date->format( 'U' ) );
                        }
                    }
                    $idx++;
                }
            }
        }
        return [ $titleText, implode( ' ', $mainText ) ];
    }

    private function prepareMainText( Festivity $festivity, int $idx ) : string {
        if( $festivity->grade === LitGrade::WEEKDAY ) {
            $mainText = _( "Today is" ) . " " . $festivity->name . ".";
        } else{ 
            if( $festivity->isVigilMass ) {
                /**translators: grade, name */
                $mainText = sprintf( _( "This evening there will be a Vigil Mass for the %s %s." ), $this->LitGrade->i18n( $festivity->grade, false ), trim( str_replace( _( "Vigil Mass" ), "", $festivity->name ) ) );
            } else if( $festivity->grade < LitGrade::HIGHER_SOLEMNITY ) {
                if( $festivity->displayGrade != "" ) {
                    $mainText = sprintf(
                        /**translators: 1. (also|''), 2. grade of the festivity, 3. name of the festivity */
                        _( 'Today is %1$s the %2$s of %3$s.' ),
                        ( $idx > 0 ? _( "also" ) : "" ),
                        $festivity->displayGrade,
                        $festivity->name
                    );
                } else {
                    if( $festivity->grade === LitGrade::FEAST_LORD ) {
                        $mainText = sprintf(
                            /**translators: CTXT: Feast of the Lord. 1. (also|''), 2. grade of the festivity, 3. name of the festivity */
                            _( 'Today is %1$s the %2$s, %3$s.' ),
                            ( $idx > 0 ? _( "also" ) : "" ),
                            $this->LitGrade->i18n( $festivity->grade, false ),
                            $festivity->name
                        );
                    } else {
                        $mainText = sprintf(
                            /**translators: CTXT: (optional) memorial or feast. 1. (also|''), 2. grade of the festivity, 3. name of the festivity */
                            _( 'Today is %1$s the %2$s of %3$s.' ),
                            ( $idx > 0 ? _( "also" ) : "" ),
                            $this->LitGrade->i18n( $festivity->grade, false ),
                            $festivity->name
                        );
                    }
                }
                
                if( $festivity->grade < LitGrade::FEAST && $festivity->common != LitCommon::PROPRIO ) {
                    $mainText = $mainText . " " . $this->LitCommon->i18n( $festivity->common );
                }
            } else {
                $mainText = sprintf(
                    /**translators: CTXT: higher grade solemnity with precedence over other solemnities. 1. (also|''), 2. name of the festivity  */
                    _( 'Today is %1$s the day of %2$s.' ),
                    ( $idx > 0 ? _( "also" ) : "" ),
                    $festivity->name
                );
            }
        }
        return $mainText;
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
