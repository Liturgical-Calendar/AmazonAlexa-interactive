<?php

class Card {
    public string $type;
    public string $title;
    public string $text;
    public ?object $image = null;

    public function __construct( $type, $title, $text, $image = null ) {
        $this->type     = $type;
        $this->title    = $title;
        $this->text     = $text;
        $this->image    = $image;
        /*
        $response->response->card->image = new stdClass();
        $response->response->card->image->smallImageUrl = "https://url-to-small-card-image...";
        $response->response->card->image->largeImageUrl = "https://url-to-large-card-image...";
        */
    }
}
