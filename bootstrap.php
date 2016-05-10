<?php

namespace Kelunik\CommitBot;

use Aerys\Host;
use Aerys\InternalRequest;
use Aerys\Middleware;
use Aerys\Session\Redis as RedisSession;
use Amp\Artax\Client;
use Amp\Artax\HttpClient;
use Amp\Beanstalk\BeanstalkClient;
use Amp\Mysql;
use Amp\Redis;
use Auryn\Injector;
use Kelunik\CommitBot\Check;
use Kelunik\CommitBot\OAuth\GitHub;
use Kelunik\CommitBot\OAuth\Provider;
use Kelunik\CommitBot\Queue\BeanstalkProducer;
use Kelunik\CommitBot\Queue\Producer;
use function Aerys\root;
use function Aerys\router;
use function Aerys\session;

$config = json_decode(file_get_contents(__DIR__ . "/res/config/config.json"), true);

$injector = new Injector;

$injector->define(\Mustache_Engine::class, [
    ":options" => [
        "loader" => new \Mustache_Loader_FilesystemLoader(__DIR__ . "/res/templates"),
    ],
]);

$injector->define(GitHub::class, [
    ":clientId" => $config["github"]["clientId"],
    ":clientSecret" => $config["github"]["clientSecret"],
    ":scope" => "write:repo_hook,repo:status",
]);

$injector->define(Mysql\Pool::class, [
    ":connStr" => $config["mysql"],
]);

$injector->define(Redis\Client::class, [
    ":uri" => $config["redis"],
]);

$injector->define(Redis\Mutex::class, [
    ":uri" => $config["redis"],
    ":options" => [],
]);

$injector->define(BeanstalkClient::class, [
    ":uri" => $config["beanstalk"],
]);

$injector->define(BeanstalkProducer::class, [
    ":name" => "github",
]);

$injector->alias(HttpClient::class, Client::class);
$injector->alias(Provider::class, GitHub::class);
$injector->alias(Producer::class, BeanstalkProducer::class);

$injector->prepare(Client::class, function (Client $client) {
    $client->setOption(Client::OP_DEFAULT_USER_AGENT, "kelunik/commit-bot");
});

$auth = $injector->make(Auth::class);
$setup = $injector->make(Setup::class);
$hook = $injector->make(Hook::class);
$display = $injector->make(Display::class);

$router = router()
    ->use(session($injector->make(RedisSession::class)))
    ->use($auth)
    ->route("GET", "", $injector->make(Dashboard::class))
    ->route("GET", "login", [$auth, "login"])
    ->route("GET", "login/github", [$auth, "doLogin"])
    ->route("POST", "login/github", [$auth, "doLoginRedirect"])
    ->route("POST", "logout", [$auth, "logout"])
    ->route("GET", "setup", [$setup, "show"])
    ->route("POST", "setup", [$setup, "process"])
    ->route("POST", "github/{owner}/{repository}", $hook)
    ->route("GET", "github/{owner}/{repository}/{sha}", $display)
    ->route("GET", "{path:.*}", root(__DIR__ . "/res/public"));

(new Host)
    ->expose("*", 4444)
    ->use($router)
    ->use(new class implements Middleware {
        public function do (InternalRequest $request) {
            $headers = yield;

            $headers["x-frame-options"] = ["SAMEORIGIN"];
            $headers["x-xss-protection"] = ["1; mode=block"];
            $headers["x-ua-compatible"] = ["IE=Edge,chrome=1"];
            $headers["x-content-type-options"] = ["nosniff"];

            if ($request->client->isEncrypted) {
                $headers["strict-transport-security"] = ["max-age=31536000"];
            }

            return $headers;
        }
    });