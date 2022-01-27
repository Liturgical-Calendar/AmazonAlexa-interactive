<?php
include_once( 'includes/enums/AcceptHeader.php' );
include_once( 'includes/enums/RequestMethod.php' );
include_once( 'includes/enums/RequestContentType.php' );
include_once( 'includes/AlexaSkill.php' );

$AlexaSkill = new AlexaSkill();
$AlexaSkill->APICore->setAllowedRequestMethods( [ RequestMethod::GET, RequestMethod::POST, RequestMethod::OPTIONS ] );
$AlexaSkill->APICore->setAllowedRequestContentTypes( [ RequestContentType::JSON, RequestContentType::FORMDATA ] );
$AlexaSkill->APICore->setAllowedAcceptHeaders( [ AcceptHeader::JSON ] );

$timestampRequestReceived = new DateTime('NOW');

$log = $timestampRequestReceived->format('r') . PHP_EOL;
$log .= "REQUEST CONTENT TYPE:\t"  . $AlexaSkill->APICore->getRequestContentType() . PHP_EOL;
$log .= "REQUEST METHOD:\t\t"      . $AlexaSkill->APICore->getRequestMethod()      . PHP_EOL;
$log .= "REQUEST ACCEPT HEADER:\t" . $AlexaSkill->APICore->getAcceptHeader()       . PHP_EOL;
$log .= "REQUEST PARAMS:\n";
$log .= json_encode($AlexaSkill->requestParams, JSON_PRETTY_PRINT) . PHP_EOL . PHP_EOL;
file_put_contents('requests.log', $log, FILE_APPEND);
