<?php

namespace Kelunik\CommitBot;

use Mustache_Engine;

class Mustache {
    private $engine;
    
    public function __construct(Mustache_Engine $engine) {
        $this->engine = $engine;
    }
    
    public function render(string $filename, TemplateContext $context) {
        return $this->engine->render($filename, $context->getContext());
    }
}