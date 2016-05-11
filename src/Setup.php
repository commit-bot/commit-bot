<?php

namespace Kelunik\CommitBot;

use Aerys\ParsedBody;
use Aerys\Request;
use Aerys\Response;
use Aerys\Session;
use Amp\Artax\HttpClient;
use Amp\Mysql;
use function Aerys\parseBody;
use function phpish\link_header\parse as parseLinks;

class Setup {
    private $mysql;
    private $httpClient;
    private $mustache;

    public function __construct(Mysql\Pool $mysql, HttpClient $httpClient, Mustache $mustache) {
        $this->mysql = $mysql;
        $this->httpClient = $httpClient;
        $this->mustache = $mustache;
    }

    public function show(Request $request, Response $response) {
        /** @var Session $session */
        $session = yield (new Session($request))->read();

        if (!$session->has(SessionKeys::LOGIN)) {
            $response->setStatus(302);
            $response->setHeader("location", "/login");
            $response->end("");

            return;
        }

        $response->end($this->mustache->render("setup.mustache", new TemplateContext($request)));
    }

    public function process(Request $request, Response $response) {
        /** @var Session $session */
        $session = yield (new Session($request))->read();

        if (!$session->has(SessionKeys::LOGIN)) {
            $response->setStatus(302);
            $response->setHeader("location", "/login");
            $response->end("");

            return;
        }

        $response->push("/css/screen.css");
        $response->push("//fonts.googleapis.com/css?family=Bitter|Source+Code+Pro:300,400,700");
        $response->stream("");
        $response->flush();

        /** @var ParsedBody $body */
        $body = yield parseBody($request);

        $repository = $body->get("repository");
        $repositoryInfo = explode("/", $repository);

        if (count($repositoryInfo) !== 2) {
            $error = "Invalid repository name.";
        } else if (in_array($repositoryInfo[1], [".", ".."])) {
            $error = "Reserved repository name.";
        }

        list ($owner, $repository) = $repositoryInfo;

        if (isset($error)) {
            $response->end($this->mustache->render("setup.mustache", new TemplateContext($request, [
                "error" => $error,
                "repository" => $owner . "/" . $repository,
            ])));

            return;
        }

        $user = $request->getLocalVar(RequestKeys::USER);

        $httpRequest = (new \Amp\Artax\Request)
            ->setUri("https://api.github.com/repos/$owner/$repository")
            ->setHeader("authorization", "token {$user["github_token"]}");

        /** @var \Amp\Artax\Response $httpResponse */
        $httpResponse = yield $this->httpClient->request($httpRequest);

        if ($httpResponse->getStatus() !== 200) {
            $response->end($this->mustache->render("setup.mustache", new TemplateContext($request, [
                "error" => "Couldn't fetch remote repository.",
                "repository" => $owner . "/" . $repository,
            ])));

            return;
        }

        $repositoryInfo = json_decode($httpResponse->getBody());
        $isAdmin = $repositoryInfo->permissions->admin ?? false;

        if (!$isAdmin) {
            $response->end($this->mustache->render("setup.mustache", new TemplateContext($request, [
                "error" => "Insufficient repository permissions.",
                "repository" => $owner . "/" . $repository,
            ])));

            return;
        }

        $currentHook = yield from $this->fetchCurrentHook($user["github_token"], $owner, $repository);

        if (!$currentHook) {
            try {
                yield from $this->createHook($user["github_token"], $owner, $repository, $user["id"]);
            } catch (\RuntimeException $e) {
                $response->end($this->mustache->render("setup.mustache", new TemplateContext($request, [
                    "error" => "Hook creation failed.",
                    "repository" => $owner . "/" . $repository,
                ])));

                return;
            }
        } else {
            if (!$currentHook->active || $currentHook->events !== "pull_request" || $currentHook->config->content_type !== "json") {
                try {
                    yield from $this->updateHook($user["github_token"], $owner, $repository, $currentHook->id, $user["id"]);
                } catch (\RuntimeException $e) {
                    $response->end($this->mustache->render("setup.mustache", new TemplateContext($request, [
                        "error" => "Hook update failed.",
                        "repository" => $owner . "/" . $repository,
                    ])));

                    return;
                }
            }
        }

        $response->end($this->mustache->render("setup-success.mustache", new TemplateContext($request)));
    }

    private function fetchCurrentHook(string $token, string $owner, string $repository): \Generator {
        $uri = "https://api.github.com/repos/$owner/$repository/hooks";

        do {
            $httpRequest = (new \Amp\Artax\Request)
                ->setUri($uri)
                ->setHeader("authorization", "token $token");

            /** @var \Amp\Artax\Response $httpResponse */
            $httpResponse = yield $this->httpClient->request($httpRequest);

            if ($httpResponse->getStatus() !== 200) {
                throw new \RuntimeException("Bad status code: " . $httpResponse->getStatus());
            }

            $hooks = json_decode($httpResponse->getBody());
            $start = "https://commit-bot.kelunik.com/github/";
            $startLength = strlen($start);

            foreach ($hooks as $hook) {
                if (substr($hook->config->url ?? "", 0, $startLength) === $start) {
                    return $hook;
                }
            }

            $uri = null;

            if ($httpResponse->hasHeader("link")) {
                $links = parseLinks($httpResponse->getHeader("link")[0]);

                if (isset($links["next"])) {
                    $uri = $links["next"];
                }
            }
        } while ($uri);

        return null;
    }

    private function createHook(string $token, string $owner, string $repository, int $user): \Generator {
        $secret = bin2hex(random_bytes(32));

        $payload = [
            "name" => "web",
            "config" => [
                "url" => "https://commit-bot.kelunik.com/github/$owner/$repository",
                "content_type" => "json",
                "secret" => $secret,
            ],
            "events" => ["pull_request"],
            "active" => true,
        ];

        $httpRequest = (new \Amp\Artax\Request)
            ->setMethod("POST")
            ->setUri("https://api.github.com/repos/$owner/$repository/hooks")
            ->setHeader("authorization", "token $token")
            ->setBody(json_encode($payload));

        /** @var \Amp\Artax\Response $httpResponse */
        $httpResponse = yield $this->httpClient->request($httpRequest);

        if ($httpResponse->getStatus() !== 201) {
            throw new \RuntimeException("Bad status code: " . $httpResponse->getStatus());
        }

        yield from $this->storeHookSecret($owner, $repository, $secret, $user);
    }

    private function updateHook(string $token, string $owner, string $repository, int $hook, int $user): \Generator {
        $secret = bin2hex(random_bytes(32));

        $payload = [
            "name" => "web",
            "config" => [
                "url" => "https://commit-bot.kelunik.com/github/$owner/$repository",
                "content_type" => "json",
                "secret" => $secret,
            ],
            "events" => ["pull_request"],
            "active" => true,
        ];

        $httpRequest = (new \Amp\Artax\Request)
            ->setMethod("PATCH")
            ->setUri("https://api.github.com/repos/$owner/$repository/hooks/$hook")
            ->setHeader("authorization", "token $token")
            ->setBody(json_encode($payload));

        /** @var \Amp\Artax\Response $httpResponse */
        $httpResponse = yield $this->httpClient->request($httpRequest);

        if ($httpResponse->getStatus() !== 200) {
            throw new \RuntimeException("Bad status code: " . $httpResponse->getStatus());
        }

        yield from $this->storeHookSecret($owner, $repository, $secret, $user);
    }

    private function storeHookSecret(string $owner, string $repository, string $secret, int $user): \Generator {
        yield $this->mysql->prepare("REPLACE INTO hooks (owner, repository, secret, user) VALUES (?, ?, ?, ?)", [
            $owner, $repository, $secret, $user,
        ]);
    }
}