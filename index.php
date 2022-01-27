<?php
include_once( 'includes/enums/AcceptHeader.php' );
include_once( 'includes/enums/RequestMethod.php' );
include_once( 'includes/enums/RequestContentType.php' );
include_once('includes/AlexaSkill.php');

$AlexaSkill = new AlexaSkill();
$AlexaSkill->APICore->setAllowedRequestMethods( [ RequestMethod::GET, RequestMethod::POST, RequestMethod::OPTIONS ] );
$AlexaSkill->APICore->setAllowedRequestContentTypes( [ RequestContentType::JSON, RequestContentType::FORMDATA ] );
$AlexaSkill->APICore->setAllowedAcceptHeaders( [ AcceptHeader::JSON ] );
