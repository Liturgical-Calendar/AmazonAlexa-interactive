<?php
include_once( 'includes/enums/LitColor.php' );
include_once( 'includes/enums/LitCommon.php' );
include_once( 'includes/enums/LitFeastType.php' );
include_once( 'includes/enums/LitGrade.php' );

/** 
 *  CLASS FESTIVITY
 *  SIMILAR TO THE CLASS USED IN THE LITCAL PHP ENGINE, 
 *  EXCEPT THAT IT CONVERTS PHP TIMESTAMP TO DATETIME OBJECT 
 *  AND DOES NOT IMPLEMENT JSONSERIALIZABLE OR COMPARATOR FUNCTION
 **/
class Festivity
{
    public string       $name;
    public DateTime     $date;
    public string       $color;
    public string       $type;
    public int          $grade;
    public string       $displayGrade;
    public string       $common;
    public string       $liturgicalYear;
    public bool         $isVigilMass;
    public string       $tag;

    function __construct( array $festivity ) {
        $this->name     = $festivity["name"];
        $this->date     = DateTime::createFromFormat( 'U', $festivity["date"], new DateTimeZone( 'UTC' ) );
        $this->color    = LitColor::isValid( $festivity["color"] ) ? $festivity["color"] : "";
        $this->type     = LitFeastType::isValid( $festivity["type"] ) ? $festivity["type"] : "";
        $this->grade    = LitGrade::isValid( $festivity["grade"] ) ? $festivity["grade"] : "";
        $this->common   = LitCommon::isValid( $festivity["common"] ) ? $festivity["common"] : "";
        $this->liturgicalYear   = $festivity["liturgicalYear"] ?? '';
        $this->displayGrade     = $festivity["displayGrade"];
        $this->isVigilMass      = $festivity["isVigilMass"] ?? false;
    }
}

