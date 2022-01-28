<?php

class OutputSpeech {
    public string $type;
    public string $text;
    public string $playBehavior = "REPLACE_ENQUEUED";

    public function __construct( $type, $text ) {
        $this->type = $type;
        $this->text = $text;
    }
}
