<?php
ini_set("log_errors", 1);
ini_set("error_log", "./php-error.log");
error_log( "Hello, errors!" );

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
    //private array $LitCalFeed       = [];
    private IntlDateFormatter $monthDayFmt;
    private IntlDateFormatter $dowMonthDayFmt;
    private NumberFormatter $ordFmt;
    //private string $Locale          = "en-US";
    private string $CanonLocale     = "en_US";
    private string $LitLocale       = LitLocale::ENGLISH;
    private string $Region          = "USA";
    private int $currentYear;

    public function __construct(){
        $this->APICore = new APICore();
        $this->output = new Response();
        $timestampRequestReceived = new DateTime('NOW');
        $this->log[] = $timestampRequestReceived->format('r');
        $this->currentYear = intval( $timestampRequestReceived->format('Y') );
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
            //$this->Locale       = $request->locale;                                                 //e.g. "en-US"
            $this->CanonLocale  = Locale::canonicalize( $request->locale );                         //e.g. "en_US"
            $this->LitLocale    = strtoupper( Locale::getPrimaryLanguage( $request->locale ) );     //e.g. "en"
            if( Locale::getRegion( $request->locale ) === 'US' ) {
                //Since we use "USA" instead of "UNITED STATES" to designate the national calendar in the LitCal project,
                //we must manually set the Region in this case
                $this->Region   = 'USA';
            } else {
                $this->Region   = strtoupper( Locale::getDisplayRegion( $request->locale, 'en' ) ); //e.g. "ITALY"
            }
        }
        $localeArray = [
            $this->CanonLocale . '.utf8',
            $this->CanonLocale . '.UTF-8',
            $this->CanonLocale,
            Locale::getPrimaryLanguage( $request->locale )
        ];
        setlocale( LC_ALL, $localeArray );
        bindtextdomain("catholicliturgy", "i18n");
        textdomain("catholicliturgy");
        $this->monthDayFmt  = IntlDateFormatter::create($this->CanonLocale, IntlDateFormatter::FULL, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, 'd MMMM' );
        $this->LitCommon    = new LitCommon( $this->LitLocale );
        $this->LitGrade     = new LitGrade( $this->LitLocale );
        if( $this->LitLocale === LitLocale::ENGLISH ) {
            $this->dowMonthDayFmt = IntlDateFormatter::create($this->CanonLocale, IntlDateFormatter::FULL, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, 'EEEE, MMMM ' );
            $this->ordFmt = NumberFormatter::create($this->CanonLocale, NumberFormatter::ORDINAL);
        } else {
            $this->dowMonthDayFmt = IntlDateFormatter::create($this->CanonLocale, IntlDateFormatter::FULL, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, 'EEEE, d MMMM' );
        }
    }

    private function retrieveYearFromSlots( object $slots) : ?int {
        $year = null;
        if( $year === null && property_exists( $slots->date, 'value' ) ) {
            $year = intval( $slots->date->value );
        }
        if( ($year === null || $year === 0) && property_exists( $slots->year, 'value' ) ) {
            $year = intval( $slots->year->value );
        }
        /*if( ($year === null || $year === 0) && property_exists( $slots->century, 'value' ) && property_exists( $slots->decade, 'value' ) ) {
            $century = intval( $slots->century->value );
            $decade = intval( $slots->decade->value );
            $year = $century + $decade;
        }
        */
        return $year;
    }

    private function handleRequest() {
        $this->verifyApplicationId();
        if( property_exists( $this->requestParams, 'request' ) ) {
            $request = $this->requestParams->request;

            $this->log[] = "requestType:\t{$request->type}";

            switch( $request->type ) {
                case 'LaunchRequest':
                    $queryArray = [];
                    $queryArray[ "locale" ] = $this->LitLocale;
                    $queryArray[ "nationalcalendar" ] = $this->Region;
                    $this->sendAPIRequest( $queryArray );
                    $dateTimeToday = ( new DateTime( 'now' ) )->format( "Y-m-d" ) . " 00:00:00";
                    $dateToday = DateTime::createFromFormat( 'Y-m-d H:i:s', $dateTimeToday, new DateTimeZone( 'UTC' ) );
                    $dateTodayTimestamp = $dateToday->format( "U" );
                    [ $titleText, $mainText ] = $this->filterEventsForDate( $dateTodayTimestamp );
                    //file_put_contents('requests.log', implode(PHP_EOL, $log) . PHP_EOL . PHP_EOL, FILE_APPEND);
                    $defaultReponse = [
                        _( "Hello from Catholic Liturgy!" ),
                        $mainText . ' ' . _( "If you'd like any information about the Liturgical year, just ask!" )
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
                    $slots = property_exists( $intent, 'slots' ) ? $intent->slots : null;
                    switch( $intent->name ) {
                        case 'WhichDayForGivenFeast':
                            $year = $this->retrieveYearFromSlots( $slots );
                            $rank = property_exists( $slots->rank, 'value' ) ? $slots->rank->value : null;

                            [$fest, $festName] = $this->retrieveBestValue( $slots->festivity );
                            $this->log[] = "slot festivity: best value = \t{$fest}";
                            $queryArray = [];
                            $queryArray[ "locale" ] = $this->LitLocale;
                            $queryArray[ "nationalcalendar" ] = $this->Region;
                            if( $year !== null && $year === 0 ) {
                                $titleText = _( 'Catholic Liturgy is confused.' );
                                $mainText = _( 'I am terribly sorry, but it seems like you are asking about a year, and I simply did not understand what year you were asking about. Could you perhaps ask again?' );
                            }
                            else if( $year !== null && $year >= 1969 && $year <= 9999 ) {
                                $queryArray[ "year" ] = $year;
                            }
                            $this->sendAPIRequest( $queryArray );
                            $LitCal = $this->LitCalData["LitCal"];
                            if( isset( $LitCal[$fest] ) ) {
                                $festivity = new Festivity( $LitCal[$fest] );
                                $titleText = sprintf(
                                    _( 'Date of %1$s in the year %2$d' ),
                                    $festivity->name,
                                    $year
                                );
                                $formattedDate = $this->dowMonthDayFmt->format( $festivity->date->format( 'U' ) );
                                if( $this->LitLocale === LitLocale::ENGLISH ) {
                                    $formattedDate .= $this->ordFmt->format( $festivity->date->format( 'j' ) );
                                }
                                if( $festivity->grade === LitGrade::HIGHER_SOLEMNITY ) {
                                    if( $year > $this->currentYear ) {
                                        $message = _( '%1$s falls on %2$s in the year %3$d.' );
                                    }
                                    else if( $year === $this->currentYear ) {
                                        $message = _( 'This year %1$s falls on %2$s.' );
                                    }
                                    else {
                                        $message = _( '%1$s fell on %2$s in the year %3$d.' );
                                    }
                                    $mainText = sprintf(
                                        $message,
                                        $festivity->name,
                                        $formattedDate,
                                        $year
                                    ); 
                                } else {
                                    $festivityGrade = $festivity->displayGrade != "" ? $festivity->displayGrade : $this->LitGrade->i18n( $festivity->grade );
                                    if( $year > $this->currentYear ) {
                                        $message = _( 'The %1$s: %2$s, falls on %3$s in the year %4$d.' );
                                    }
                                    else if( $year === $this->currentYear ) {
                                        $message = _( 'This year, the %1$s: %2$s, falls on %3$s.' );
                                    }
                                    else {
                                        $message = _( 'The %1$s: %2$s, fell on %3$s in the year %4$d.' );
                                    }
                                    $mainText = sprintf(
                                        $message,
                                        $festivityGrade,
                                        $festivity->name,
                                        $formattedDate,
                                        $year
                                    );
                                }
                            }
                            else if ( $this->litCalMessagesExist( $festName ) ) {
                                $messages = [];
                                foreach( $this->LitCalData["Messages"] as $message ) {
                                    if( strpos( $message, $festName ) ) {
                                        $messages[] = strip_tags( $message );
                                    }
                                }
                                $titleText = sprintf( _( 'What happened to %1$s in %2$d' ), $slots->festivity->value, $year );
                                $mainText = sprintf(
                                    _( 'Catholic Liturgy gathered the following information about %s:' ),
                                    $festName
                                );
                                $mainText .= ' ' . implode(' ', $messages );
                            }
                            else {
                                $titleText = _( 'Catholic Liturgy is confused.' );
                                $mainText = sprintf(
                                    _( 'Sorry, I could not find any information about %s.' ),
                                    $slots->festivity->value
                                );
                            }

                            if( $this->LitLocale === LitLocale::ENGLISH ) {
                                if ( $fest === 'StAngelaMerici' ) {
                                    $spokenText = str_replace( 'Angela Merici', "<lang xml:lang='it-IT'>Angela Merici</lang>", $mainText );
                                }
                                else if( stripos($festName, 'Blessed') ) {
                                    $spokenText = str_ireplace( 'Blessed', "<phoneme alphabet='ipa' ph='ˈblesɪd'>Blessed</phoneme>", $mainText );
                                }
                                else {
                                    $spokenText = $mainText;
                                }
                            } else {
                                $spokenText = $mainText;
                            }
                            $alexaResponse = new AlexaResponse( false );
                            $alexaResponse->outputSpeech = new OutputSpeech( "SSML", $spokenText );
                            $alexaResponse->card = new Card( "Standard", $titleText, $mainText );
        
                            $this->output->response = $alexaResponse;
        
                            break;
                        case 'WhichLiturgyForGivenDay':
                            $this->log[] = "Received request with Intent === WhichLiturgyForGivenDay";
                            if( property_exists( $slots->date, 'value' ) && 
                                $slots->date->value !== '?' && 
                                preg_match( '/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/', $slots->date->value ) !== 0
                            ) {
                                $this->log[] = "WhichLiturgyForGivenDay: DAY === ". $slots->date->value;
                                $date = DateTime::createFromFormat('Y-m-d H:i:s e', $slots->date->value . ' 00:00:00 UTC' );
                                $queryArray = [];
                                $queryArray[ "locale" ] = $this->LitLocale;
                                $queryArray[ "nationalcalendar" ] = $this->Region;
                                $queryArray[ "year" ] = $date->format('Y');
                                $this->log[] = "Requesting liturgical calendar for the year " . $queryArray["year"];
                                $this->sendAPIRequest( $queryArray );
                                $timestamp = $date->format('U');
                                [ $titleText, $mainText ] = $this->filterEventsForDate( $timestamp );
                            } else {
                                $titleText = _( 'Catholic Liturgy is confused.' );
                                $mainText = _( 'I am terribly sorry, but it seems like you are inquiring about the liturgy of a specific day. I however simply did not understand what day you were asking about. Could you perhaps ask again?' );
                            }
                            $this->log[] = $titleText;
                            $this->log[] = $mainText;
                            $spokenText = $mainText;
                            $alexaResponse = new AlexaResponse( false );
                            $alexaResponse->outputSpeech = new OutputSpeech( "SSML", $spokenText );
                            $alexaResponse->card = new Card( "Standard", $titleText, $mainText );
        
                            $this->output->response = $alexaResponse;
                            break;
                        case 'AMAZON.FallbackIntent':
                            $alexaResponse = new AlexaResponse( false );
                            $message = _( "I'm not sure I understand what you are asking. Did you want the Liturgy of the day?" );
                            $alexaResponse->outputSpeech = new OutputSpeech( "SSML", $message );
                            $alexaResponse->card = new Card( "Standard","Confused", $message );
                            $this->output->response = $alexaResponse;
                            break;
                    }
                    break;
                case 'SessionEndedRequest':
                    $alexaResponse = new AlexaResponse( true );
                    $message = _( "It has been a pleasure to be of service. Go in peace to love and serve the Lord!" );
                    $alexaResponse->outputSpeech = new OutputSpeech( "SSML", $message );
                    $alexaResponse->card = new Card( "Standard","Good bye!", $message );
                    $this->output->response = $alexaResponse;
                    break;
                }
        }
    }

    private function litCalMessagesExist( string $slotVal ) : bool {
        foreach( $this->LitCalData["Messages"] as $message ) {
            if( strpos( $message, $slotVal ) ) {
                return true;
            }
        }
        return false;
    }

    private function retrieveBestValue( object $slot ) : array {
        if( property_exists( $slot, 'resolutions' ) ) {
            $resPerAuth = $slot->resolutions->resolutionsPerAuthority;
            $bestRes = $resPerAuth[0];
            return [$bestRes->values[0]->value->id,$bestRes->values[0]->value->name];
        }
        return [$slot->value,$slot->value];
    }

    private function filterEventsForDate( string $timestamp ) : array {
        $titleText = '';
        $mainText = [];
        if( isset( $this->LitCalData["LitCal"] ) ) {
            $LitCal = $this->LitCalData["LitCal"];
            $idx = 0;
            foreach ( $LitCal as $key => $value ) {
                //fwrite( $logFile, "Processing litcal event $key..." . "\n" );
                if( $LitCal[$key]["date"] === $timestamp ) {
                    // retransform each entry from an associative array to a Festivity class object
                    $festivity = new Festivity( $LitCal[$key] );
                    $festivity->tag = $key;
                    $mainText[] = $this->prepareMainText( $festivity, $idx, $timestamp );
                    //fwrite( $logFile, "mainText = $mainText" . "\n" );
                    if( $idx === 0 ) {
                        $titleText = _( "Liturgy of the Day" ) . " ";
                        if( $this->LitLocale === LitLocale::ENGLISH ) {
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

    private function prepareMainTextToday( Festivity $festivity, int $idx, bool $isSundayOrdAdvLentEaster ) : string {
        if( $festivity->grade === LitGrade::WEEKDAY ) {
            $mainText = _( "Today is" ) . " " . $festivity->name . ".";
        } else{ 
            if( $festivity->isVigilMass ) {
                if( $isSundayOrdAdvLentEaster ) {
                    $mainText = sprintf(
                        /**translators: 1. name of the festivity */
                        _( 'This evening there will be a Vigil Mass for the %1$s.' ),
                        trim( str_replace( _( "Vigil Mass" ), "", $festivity->name ) )
                    );
                } else {
                    $mainText = sprintf(
                        /**translators: 1. grade of the festivity, 2. name of the festivity */
                        _( 'This evening there will be a Vigil Mass for the %1$s %2$s.' ),
                        $this->LitGrade->i18n( $festivity->grade, false ),
                        trim( str_replace( _( "Vigil Mass" ), "", $festivity->name ) )
                    );
                }
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
                        if( $isSundayOrdAdvLentEaster ) {
                            $mainText = sprintf(
                                /**translators: CTXT: Sundays. 1. (also|''), 2. name of the festivity */
                                _( 'Today is %1$s the %2$s.' ),
                                ( $idx > 0 ? _( "also" ) : "" ),
                                $festivity->name
                            );
    
                        } else {
                            $mainText = sprintf(
                                /**translators: CTXT: Feast of the Lord. 1. (also|''), 2. grade of the festivity, 3. name of the festivity */
                                _( 'Today is %1$s the %2$s, %3$s.' ),
                                ( $idx > 0 ? _( "also" ) : "" ),
                                $this->LitGrade->i18n( $festivity->grade, false ),
                                $festivity->name
                            );
                        }
                    }
                    else if( strpos( $festivity->tag, "SatMemBVM" ) !== false ) {
                        $mainText = sprintf(
                            /**translators: CTXT: Saturday memorial BVM. 1. (also|''), 2. name of the festivity */
                            _( 'Today is %1$s the %2$s.' ),
                            ( $idx > 0 ? _( "also" ) : "" ),
                            $festivity->name
                        );
                    }
                    else {
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
                    $mainText = $mainText . " " . $this->LitCommon->C( $festivity->common ) . ".";
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

    private function prepareMainTextAnyDay( Festivity $festivity, int $idx, string $timestamp, string $dateTodayTimestamp, bool $isSundayOrdAdvLentEaster ) : string {
        $date = DateTime::createFromFormat( 'U', $timestamp );
        $year = $date->format('Y');
        $isNotThisYear = true;
        if( date("Y") === $year ) {
            $isNotThisYear = false;
        }
        $tense = "present";
        if( intval($timestamp) > intval($dateTodayTimestamp) ) {
            $tense = "future";
        } else {
            $tense = "past";
        }
        $day = $this->dowMonthDayFmt->format( $timestamp );
        if( $this->LitLocale === LitLocale::ENGLISH ) {
            $day .= ' ' . $this->ordFmt->format( $date->format( 'j' ) );
        }
        if( $festivity->grade === LitGrade::WEEKDAY ) {
            if( $tense === "future" ) {
                $mainText = sprintf( 
                    /**translators: 1. date 2. festivity */
                    _( '%1$s is %2$s' ),
                    $day,
                    $festivity->name
                );
            } else {
                $mainText = sprintf(
                    /**translators: 1. date 2. festivity */
                    _( '%1$s was %2$s' ),
                    $day,
                    $festivity->name
                );
            }
            if( $isNotThisYear ) {
                $mainText .= ' ' . sprintf(_( 'in the year %1$d' ), $year) . ".";
            } else {
                $mainText .= ".";
            }
        } else {
            if( $festivity->isVigilMass ) {
                if( $isSundayOrdAdvLentEaster ) {
                    if( $tense === "future" ) {
                        /**translators: 1. name of the festivity */
                        $message = _( 'In the evening there will be a Vigil Mass for the %1$s.' );
                    } else {
                        /**translators: 1. name of the festivity */
                        $message = _( 'In the evening there was a Vigil Mass for the %1$s.' );
                    }
                    $mainText = sprintf( 
                        $message,
                        trim( str_replace( _( "Vigil Mass" ), "", $festivity->name ) )
                    );
                } else {
                    if( $tense === "future" ) {
                        /**translators: 1. grade of the festivity, 2. name of the festivity */
                        $message = _( 'In the evening there will be a Vigil Mass for the %1$s %2$s.' );
                    } else {
                        /**translators: 1. grade of the festivity, 2. name of the festivity */
                        $message = _( 'In the evening there was a Vigil Mass for the %1$s %2$s.' );
                    }
                    $mainText = sprintf(
                        $message,
                        $this->LitGrade->i18n( $festivity->grade, false ),
                        trim( str_replace( _( "Vigil Mass" ), "", $festivity->name ) )
                    );
                }
            } else if( $festivity->grade < LitGrade::HIGHER_SOLEMNITY ) {
                if( $festivity->displayGrade != "" ) {
                    if( $tense === "future" ) {
                        /**translators: 1. date, 2. (also|''), 3. grade of the festivity, 4. name of the festivity */
                        $message = _( '%1$s is %2$s the %3$s of %4$s' );
                    } else {
                        /**translators: 1. date, 2. (also|''), 3. grade of the festivity, 4. name of the festivity */
                        $message = _( '%1$s was %2$s the %3$s of %4$s' );
                    }
                    $mainText = sprintf(
                        $message,
                        $day,
                        ( $idx > 0 ? _( "also" ) : "" ),
                        $festivity->displayGrade,
                        $festivity->name
                    );
                    if( $isNotThisYear ) {
                        $mainText .= ' ' . sprintf(_( 'in the year %1$d' ), $year) . ".";
                    } else {
                        $mainText .= ".";
                    }
                } else {
                    if( $festivity->grade === LitGrade::FEAST_LORD ) {
                        if( $isSundayOrdAdvLentEaster ) {
                            if( $tense === "future" ) {
                                /**translators: CTXT: Sundays. 1. date, 2. (also|''), 3. name of the festivity */
                                $message = _( '%1$s is %2$s the %3$s' );
                            } else {
                                /**translators: CTXT: Sundays. 1. date, 2. (also|''), 3. name of the festivity */
                                $message = _( '%1$s was %2$s the %3$s' );
                            }
                            $mainText = sprintf(
                                $message,
                                $day,
                                ( $idx > 0 ? _( "also" ) : "" ),
                                $festivity->name
                            );
                        } else {
                            if( $tense === "future" ) {
                                /**translators: CTXT: Feast of the Lord. 1. date, 2. (also|''), 3. grade of the festivity, 4. name of the festivity */
                                $message = _( '%1$s is %2$s the %3$s, %4$s' );
                            } else {
                                /**translators: CTXT: Feast of the Lord. 1. date, 2. (also|''), 3. grade of the festivity, 4. name of the festivity */
                                $message = _( '%1$s was %2$s the %3$s, %4$s' );
                            }
                            $mainText = sprintf(
                                $message,
                                $day,
                                ( $idx > 0 ? _( "also" ) : "" ),
                                $this->LitGrade->i18n( $festivity->grade, false ),
                                $festivity->name
                            );
                        }
                    }
                    else if( strpos( $festivity->tag, "SatMemBVM" ) !== false ) {
                        if( $tense === "future" ) {
                            /**translators: CTXT: Saturday memorial BVM. 1. date, 2. (also|''), 3. name of the festivity */
                            $message = _( '%1$s is %2$s the %3$s' );
                        } else {
                            /**translators: CTXT: Saturday memorial BVM. 1. date, 2. (also|''), 3. name of the festivity */
                            $message = _( '%1$s was %2$s the %3$s' );
                        }
                        $mainText = sprintf(
                            $message,
                            $day,
                            ( $idx > 0 ? _( "also" ) : "" ),
                            $festivity->name
                        );
                    }
                    else {
                        if( $tense === "future" ) {
                            /**translators: CTXT: (optional) memorial or feast. 1. date, 2. (also|''), 3. grade of the festivity, 4. name of the festivity */
                            $message = _( '%1$s is %2$s the %3$s of %4$s' );
                        } else {
                            /**translators: CTXT: (optional) memorial or feast. 1. date, 2. (also|''), 3. grade of the festivity, 4. name of the festivity */
                            $message = _( '%1$s was %2$s the %3$s of %4$s' );
                        }
                        $mainText = sprintf(
                            $message,
                            $day,
                            ( $idx > 0 ? _( "also" ) : "" ),
                            $this->LitGrade->i18n( $festivity->grade, false ),
                            $festivity->name
                        );
                    }
                    if( $isNotThisYear ) {
                        $mainText .= ' ' . sprintf(_( 'in the year %1$d' ), $year) . ".";
                    } else {
                        $mainText .= ".";
                    }
                }

                if( $festivity->grade < LitGrade::FEAST && $festivity->common != LitCommon::PROPRIO ) {
                    $mainText = $mainText . " " . $this->LitCommon->C( $festivity->common ) . ".";
                }
            } else {
                if( $tense === "future" ) {
                /**translators: CTXT: higher grade solemnity with precedence over other solemnities. 1. date, 2. (also|''), 3. name of the festivity  */
                $message = _( '%1$s is %2$s the day of %3$s' );
                } else {
                /**translators: CTXT: higher grade solemnity with precedence over other solemnities. 1. date, 2. (also|''), 3. name of the festivity  */
                $message = _( '%1$s was %2$s the day of %3$s' );
                }
                $mainText = sprintf(
                    $message,
                    $day,
                    ( $idx > 0 ? _( "also" ) : "" ),
                    $festivity->name
                );
                if( $isNotThisYear ) {
                    $mainText .= ' ' . sprintf(_( 'in the year %1$d' ), $year) . ".";
                } else {
                    $mainText .= ".";
                }
            }
        }
        return $mainText;
    }

    private function prepareMainText( Festivity $festivity, int $idx, string $timestamp ) : string {
        //Situations in which we don't need to actually state "Feast of the Lord":
        $filterTagsDisplayGrade = [
            "/OrdSunday[0-9]{1,2}(_vigil){0,1}/",
            "/Advent[1-4](_vigil){0,1}/",
            "/Lent[1-5](_vigil){0,1}/",
            "/Easter[1-7](_vigil){0,1}/"
        ];
        $isSundayOrdAdvLentEaster = false;
        foreach( $filterTagsDisplayGrade as $pattern ) {
            if( preg_match( $pattern, $festivity->tag ) === 1 ) {
                $isSundayOrdAdvLentEaster = true;
                break;
            }
        }

        $dateTimeToday = ( new DateTime( 'now' ) )->format( "Y-m-d" ) . " 00:00:00";
        $dateToday = DateTime::createFromFormat( 'Y-m-d H:i:s', $dateTimeToday, new DateTimeZone( 'UTC' ) );
        $dateTodayTimestamp = $dateToday->format( "U" );
        if( $timestamp === $dateTodayTimestamp ) {
            $mainText = $this->prepareMainTextToday( $festivity, $idx, $isSundayOrdAdvLentEaster );
        }
        else {
            $mainText = $this->prepareMainTextAnyDay( $festivity, $idx, $timestamp, $dateTodayTimestamp, $isSundayOrdAdvLentEaster );
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
