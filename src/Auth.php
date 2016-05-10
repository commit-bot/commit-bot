<?php

namespace Kelunik\CommitBot;

use Aerys\Bootable;
use Aerys\Logger;
use Aerys\Request;
use Aerys\Response;
use Aerys\Server;
use Aerys\Session;
use Amp\Artax\HttpClient;
use Amp\Dns\ResolutionException;
use Amp\Mysql;
use Kelunik\CommitBot\OAuth\OAuthException;
use Kelunik\CommitBot\OAuth\Provider;
use Psr\Log\LoggerInterface;

class Auth implements Bootable {
    /** @var LoggerInterface */
    private $logger;
    private $mysql;
    private $httpClient;
    private $mustache;
    private $provider;

    public function __construct(Mysql\Pool $mysql, HttpClient $httpClient, Mustache $mustache, Provider $provider) {
        $this->mysql = $mysql;
        $this->httpClient = $httpClient;
        $this->mustache = $mustache;
        $this->provider = $provider;
    }

    public function boot(Server $server, Logger $logger) {
        $this->logger = $logger;
    }

    public function __invoke(Request $request, Response $response) {
        /** @var Session $session */
        $session = yield (new Session($request))->read();

        if ($session->has(SessionKeys::LOGIN)) {
            /** @var Mysql\ResultSet $result */
            $result = yield $this->mysql->prepare("SELECT id, name, avatar, github_id, github_token FROM users WHERE id = ? LIMIT 1", [
                $session->get(SessionKeys::LOGIN),
            ]);

            if ($count = yield $result->rowCount()) {
                $request->setLocalVar(RequestKeys::USER, (array) yield $result->fetchObject());
            } else {
                $request->setLocalVar(RequestKeys::USER, [
                    "id" => 0,
                    "name" => "anonymous",
                    "avatar" => null,
                    "github_id" => 0,
                    "github_token" => null,
                ]);
            }
        } else {
            $request->setLocalVar(RequestKeys::USER, [
                "id" => 0,
                "name" => "anonymous",
                "avatar" => null,
                "github_id" => 0,
                "github_token" => null,
            ]);
        }
    }

    public function login(Request $request, Response $response) {
        /** @var Session $session */
        $session = yield (new Session($request))->read();

        if ($session->get("login")) {
            $response->setStatus(302);
            $response->setHeader("location", "/");
            $response->end("");

            return;
        }

        $response->end($this->mustache->render("auth.mustache", new TemplateContext($request)));
    }

    public function doLoginRedirect(Request $request, Response $response) {
        /** @var Session $session */
        $session = yield (new Session($request))->open();

        $token = bin2hex(random_bytes(32));
        $session->set("token:oauth", $token);

        yield $session->save();

        $url = $this->provider->getAuthorizeRedirectUrl($token);

        $response->setStatus(302);
        $response->setHeader("location", $url);
        $response->end("");
    }

    public function doLogin(Request $request, Response $response) {
        /** @var Session $session */
        $session = yield (new Session($request))->read();
        $token = $session->get("token:oauth");

        $code = $request->getParam("code");
        $state = $request->getParam("state");

        if (empty($code) || empty($state) || empty($token) || !hash_equals($token, $state)) {
            $response->setStatus(400);
            $response->setHeader("aerys-generic-response", "enable");
            $response->end("");

            return;
        }

        try {
            $accessToken = yield from $this->provider->getAccessTokenFromCode($code);
        } catch (OAuthException $e) {
            $response->setStatus(403);
            $response->setHeader("aerys-generic-response", "enable");
            $response->end("");

            return;
        } catch (ResolutionException $e) {
            $response->setStatus(503);
            $response->setHeader("aerys-generic-response", "enable");
            $response->end("");

            return;
        }

        $identity = yield from $this->provider->getIdentity($accessToken);

        if (!$identity) {
            $response->setStatus(403);
            $response->setHeader("aerys-generic-response", "enable");
            $response->end("");

            return;
        }

        /** @var Mysql\ResultSet $result */
        $result = yield $this->mysql->prepare("SELECT id, github_token FROM users WHERE github_id = ?", [
            $identity["id"],
        ]);

        $user = yield $result->fetchObject();

        yield $session->open();

        if ($user) {
            $session->set(SessionKeys::LOGIN, $user->id);

            if (!$user->github_token || !hash_equals($accessToken, $user->github_token)) {
                yield $this->mysql->prepare("UPDATE users SET github_token = ? WHERE id = ?", [
                    $accessToken, $user->id,
                ]);
            }

            $this->logger->info("Login for " . $identity["name"] . " (" . $identity["id"] . ")");
        } else {
            /** @var Mysql\ConnectionState $info */
            $info = yield $this->mysql->prepare("INSERT INTO users (name, avatar, github_id, github_token) VALUES (?, ?, ?, ?)", [
                $identity["name"], $identity["avatar"], $identity["id"], $accessToken
            ]);

            $this->logger->info("New user: " . $identity["name"] . "(" . $identity["id"] . ")");

            $session->set(SessionKeys::LOGIN, $info->insertId);
        }

        yield $session->save();

        $response->setStatus(302);
        $response->setHeader("location", "/");
        $response->end("");
    }

    public function logout(Request $request, Response $response) {
        /** @var Session $session */
        $session = yield (new Session($request))->open();

        yield $session->destroy();

        $response->setStatus(302);
        $response->setHeader("location", "/");
        $response->end("");
    }
}