<?php

namespace Kelunik\CommitBot;

use Aerys\Request;
use Aerys\Response;
use Amp\Mysql;
use Kelunik\CommitBot\Queue\Producer;

class Hook {
    private $mysql;
    private $workQueue;

    public function __construct(Mysql\Pool $mysql, Producer $workQueue) {
        $this->mysql = $mysql;
        $this->workQueue = $workQueue;
    }

    public function __invoke(Request $request, Response $response, array $args) {
        $owner = $args["owner"];
        $repository = $args["repository"];
        $secret = yield from $this->fetchSecret($owner, $repository);

        $event = $request->getHeader("x-github-event") ?? "";
        $signature = $request->getHeader("x-github-signature") ?? "";

        $rawBody = yield $request->getBody();
        $payload = json_decode($rawBody);
        $hmac = "sha1=" . hash_hmac("sha1", $rawBody, $secret);

        $response->setHeader("content-type", "text-plain");

        if (false && !hash_equals($hmac, $signature)) {
            $response->setStatus(400);
            $response->end("Bad signature.");

            return;
        }

        if ($event !== "pull_request") {
            $response->end("No pull request, aborting.");
            return;
        }

        if ($payload->action !== "opened" && $payload->action !== "synchronize") {
            $response->end("No open or synchronize event, aborting.");
            return;
        }

        $payload = [
            "owner" => $owner,
            "repository" => $repository,
            "pr" => $payload->number,
        ];

        yield $this->workQueue->publish(json_encode($payload));

        $response->end("OK, scheduled.");
    }

    private function fetchSecret(string $owner, string $repository) {
        /** @var Mysql\ResultSet $hookResult */
        $hookResult = yield $this->mysql->prepare("SELECT secret FROM hooks WHERE owner = ? && repository = ?", [
            $owner, $repository,
        ]);

        $obj = yield $hookResult->fetchObject();
        return $obj->secret ?? null;
    }
}