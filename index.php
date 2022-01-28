<?php
include_once( 'includes/enums/AcceptHeader.php' );
include_once( 'includes/enums/RequestMethod.php' );
include_once( 'includes/enums/RequestContentType.php' );
include_once( 'includes/AlexaSkill.php' );

$timestampRequestReceived = new DateTime('NOW');
$log = [ $timestampRequestReceived->format('r') ];

$AlexaSkill = new AlexaSkill();
$AlexaSkill->APICore->setAllowedRequestMethods( [ RequestMethod::POST, RequestMethod::OPTIONS ] );
$AlexaSkill->APICore->setAllowedRequestContentTypes( [ RequestContentType::JSON ] );
$AlexaSkill->APICore->setAllowedAcceptHeaders( [ AcceptHeader::JSON ] );

$AlexaSkill->Init();
die();
