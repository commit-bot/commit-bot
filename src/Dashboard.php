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
        $response->push("/css/screen.css");
        $response->push("//fonts.googleapis.com/css?family=Bitter|Source+Code+Pro:300,400,700");
        $response->end($this->mustache->render("dashboard.mustache", new TemplateContext($request)));
    }
}