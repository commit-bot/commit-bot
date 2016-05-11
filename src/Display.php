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
        $response->push("/css/screen.css");
        $response->push("//fonts.googleapis.com/css?family=Bitter|Source+Code+Pro:300,400,700");
        $response->stream("");
        $response->flush();

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
                $state = "pending";
                break;

            case 1:
                $state = "success";
                break;

            case 2:
                $state = "failure";
                break;

            case 3:
                $state = "error";
                break;

            default:
                $state = "unknown";
                break;
        }

        $response->end($this->mustache->render("job.mustache", new TemplateContext($request, [
            "owner" => $owner,
            "repository" => $repository,
            "sha" => $sha,
            "log" => $result->log,
            "state" => $state,
            "is" . ucfirst($state) => true,
        ])));
    }
}