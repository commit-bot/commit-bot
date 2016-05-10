<?php

namespace Kelunik\CommitBot;

use Aerys\Request;
use Aerys\Response;

class Dashboard {
    private $mustache;

    public function __construct(Mustache $mustache) {
        $this->mustache = $mustache;
    }

    public function __invoke(Request $request, Response $response) {
        $response->end($this->mustache->render("dashboard.mustache", new TemplateContext($request)));
    }
}