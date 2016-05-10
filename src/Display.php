<?php

namespace Kelunik\CommitBot;

use Aerys\Request;
use Aerys\Response;
use Amp\Mysql;

class Display {
    private $mysql;
    private $mustache;

    public function __construct(Mysql\Pool $mysql, Mustache $mustache) {
        $this->mysql = $mysql;
        $this->mustache = $mustache;
    }

    public function __invoke(Request $request, Response $response, array $args) {
        $owner = $args["owner"];
        $repository = $args["repository"];
        $sha = $args["sha"];

        /** @var Mysql\ResultSet $result */
        $result = yield $this->mysql->prepare("SELECT state, log FROM jobs WHERE owner = ? && repository = ? && sha = ?", [
            $owner, $repository, hex2bin($sha)
        ]);

        $result = yield $result->fetchObject();

        if (!$result) {
            return;
        }

        switch ($result->state) {
            case 0:
                $state = "PENDING";
                break;

            case 1:
                $state = "SUCCESS";
                break;

            case 2:
                $state = "FAILURE";
                break;

            case 3:
                $state = "ERROR";
                break;

            default:
                $state = "UNKNOWN";
                break;
        }

        $response->end($this->mustache->render("job.mustache", new TemplateContext($request, [
            "owner" => $owner,
            "repository" => $repository,
            "sha" => $sha,
            "log" => $result->log,
            "state" => $state,
        ])));
    }
}