<?php

class OutputSpeech {
    public string $type;
    public ?string $ssml    = null;
    public ?string $text    = null;
    public string $playBehavior = "REPLACE_ENQUEUED";

    public function __construct( $type, $text ) {
        $this->type = $type;
        switch( $type ) {
            case "PlainText":
                $this->text = $text;
                break;
            case "SSML":
                $this->ssml = "<speak>" . $text . "</speak>";
                break;
        }
    }
}
