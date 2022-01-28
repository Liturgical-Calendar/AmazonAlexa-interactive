<?php
include_once( 'includes/enums/AcceptHeader.php' );
include_once( 'includes/enums/RequestMethod.php' );
include_once( 'includes/enums/RequestContentType.php' );
include_once( 'includes/AlexaSkill.php' );
include_once( 'config.php' );

$timestampRequestReceived = new DateTime('NOW');
$log = [ $timestampRequestReceived->format('r') ];

$AlexaSkill = new AlexaSkill();
$AlexaSkill->APICore->setAllowedRequestMethods( [ RequestMethod::POST, RequestMethod::OPTIONS ] );
$AlexaSkill->APICore->setAllowedRequestContentTypes( [ RequestContentType::JSON ] );
$AlexaSkill->APICore->setAllowedAcceptHeaders( [ AcceptHeader::JSON ] );

$AlexaSkill->Init();

$sessionId = '';
$userId = null;

if( property_exists( $AlexaSkill->requestParams, 'session' ) ) {
    //$log[] = "We have a session object";
    if( $AlexaSkill->requestParams->session->application->applicationId !== APPLICATION_ID ) {
        $log[] = "Wrong application ID!";
        file_put_contents('requests.log', implode(PHP_EOL, $log) . PHP_EOL . PHP_EOL, FILE_APPEND);
        die("{\"ERROR\":\"I'm a teapot\"}");
    } else {
        $sessionId  = $AlexaSkill->requestParams->session->sessionId;
        $userId     = $AlexaSkill->requestParams->session->user->userId;
    }
}
if( property_exists( $AlexaSkill->requestParams, 'context' ) ) {
    if( $AlexaSkill->requestParams->context->System->application->applicationId !== APPLICATION_ID ) {
        $log[] = "Wrong application ID!";
        file_put_contents('requests.log', implode(PHP_EOL, $log) . PHP_EOL . PHP_EOL, FILE_APPEND);
        die("{\"ERROR\":\"I'm a teapot\"}");
    }
    if( $userId === null ) {
        $userId = $AlexaSkill->requestParams->context->System->user->userId;
    }
}

$log[] = "sessionId:\t$sessionId";
$log[] = "userId:\t$userId";

$defaultReponse = [
    "Hello from Catholic Liturgy!",
    "Today is . I can also give all sorts of information about the Liturgical year, and interesting Liturgical facts. Try me!"
];

if( property_exists( $AlexaSkill->requestParams, 'request' ) ) {
    $request = $AlexaSkill->requestParams->request;

    $log[] = "requestType:\t{$request->type}";
    file_put_contents('requests.log', implode(PHP_EOL, $log) . PHP_EOL . PHP_EOL, FILE_APPEND);

    switch( $request->type ) {
        case 'LaunchRequest':
            file_put_contents('requests.log', implode(PHP_EOL, $log) . PHP_EOL . PHP_EOL, FILE_APPEND);
            $response = new stdClass();
            $response->version = "1.0";
            $response->sessionAttributes = new stdClass();
            $response->response = new stdClass();
            
            $response->response->outputSpeech = new stdClass();
            $response->response->outputSpeech->type = "PlainText";
            $response->response->outputSpeech->text = implode(' ', $defaultReponse);
            $response->response->outputSpeech->playBehavior = "REPLACE_ENQUEUED";
            
            $response->response->card = new stdClass();
            $response->response->card->type = "Standard";
            $response->response->card->title = $defaultReponse[0];
            $response->response->card->text = $defaultReponse[1];
            /*
            $response->response->card->image = new stdClass();
            $response->response->card->image->smallImageUrl = "https://url-to-small-card-image...";
            $response->response->card->image->largeImageUrl = "https://url-to-large-card-image...";
            */
            $response->response->reprompt = new stdClass();
            $response->response->reprompt->outputSpeech = new stdClass();
            $response->response->reprompt->outputSpeech->type = "PlainText";
            $response->response->reprompt->outputSpeech->text = "I'm not sure I understand what you are asking. Did you want the Liturgy of the day?";
            $response->response->reprompt->outputSpeech->playBehavior = "REPLACE_ENQUEUED";
            /*
            $response->response->directives = new stdClass();
            $response->response->directives->type = "InterfaceName.Directive";
            */
            $response->response->shouldEndSession = false;
            
            echo json_encode( $response );
        }
}

die();
