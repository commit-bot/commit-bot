<?php

namespace Kelunik\CommitBot;

use Amp\Artax\HttpClient;
use Amp\Artax\Request;
use Amp\Artax\Response;

class GithubStatus {
    const STATE_PENDING = "pending";
    const STATE_SUCCESS = "success";
    const STATE_FAILURE = "failure";
    const STATE_ERROR = "error";

    private $httpClient;
    private $context;

    public function __construct(HttpClient $httpClient, string $context) {
        $this->httpClient = $httpClient;
        $this->context = $context;
    }

    public function setStatus(string $token, string $owner, string $repository, string $sha, string $state, string $targetUrl, string $description): \Generator {
        $payload = [
            "state" => $state,
            "target_url" => $targetUrl,
            "description" => $description,
            "context" => $this->context,
        ];

        $request = (new Request())
            ->setMethod("POST")
            ->setHeader("authorization", "token $token")
            ->setUri("https://api.github.com/repos/$owner/$repository/statuses/$sha")
            ->setBody(json_encode($payload));

        /** @var Response $response */
        $response = yield $this->httpClient->request($request);

        if ($response->getStatus() !== 201) {
            throw new \RuntimeException("Bad status code: " . $response->getStatus());
        }
    }
}