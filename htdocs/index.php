<?php

declare(strict_types=1);

namespace Nene;

use Nene\Xion as Xion;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * @author hideyuki MORI
 */

/**
 * This file is the entrance of the front controller model.
 * This file accepts all access to the controller.
 *
 * @author HideyukiMORI
 */
require_once '../vendor/autoload.php';

// Record PHP start time as early as possible so Server-Timing measures
// the full application processing time (FT20 / ADR-0007). No-op when
// NENE_SERVER_TIMING_ENABLED is unset — isEnabled() is read later by
// ResponseDecorator::headers().
Xion\ServerTiming::start();

Xion\EnvLoader::loadIfExists(dirname(__DIR__) . '/.env');
Xion\Initialize::init();

configurePublicErrorDisplay();
configureSessionCookie();
configureSessionHandler();
session_cache_expire(180);
date_default_timezone_set('Asia/Tokyo');
session_start();

// Optional Bearer auth for agent / MCP clients (FT16 / ADR-0008).
// No-op when `NENE_AGENT_BEARER_TOKEN` is unset, so the browser
// cookie+CSRF path is unchanged.
Xion\BearerAuth::resolve();

$dispatcher = new Xion\Dispatcher();
try {
    $dispatcher->dispatch();
} catch (Xion\HttpTermination $termination) {
    Xion\HttpEmitter::emit($termination->response());
} catch (Xion\DomainException $domainException) {
    $apiResponse = new Xion\ApiResponse();
    $failure = $apiResponse->failure($domainException->errorCode());
    if (Xion\RouteContext::getInstance()->isRest()) {
        Xion\HttpEmitter::emit(Xion\JsonResponder::responseArray($failure));
    } else {
        $template = (string)file_get_contents(DIR_ROOT . '/domain-error.html');
        $body = strtr($template, [
            '{{errorCode}}' => htmlspecialchars((string)$failure['errorCode'], ENT_QUOTES, 'UTF-8'),
            '{{errorMessage}}' => htmlspecialchars((string)$failure['errorMessage'], ENT_QUOTES, 'UTF-8'),
        ]);
        $status = Xion\ErrorCode::getInstance()->getHttpStatus($domainException->errorCode());
        Xion\HttpEmitter::emit(Xion\HttpResponse::html($body, $status));
    }
} catch (\Throwable $throwable) {
    Xion\Log::getInstance('error')->error('Unhandled application error.', ['exception' => $throwable]);
    if (Xion\RouteContext::getInstance()->isRest()) {
        $apiResponse = new Xion\ApiResponse();
        Xion\HttpEmitter::emit(
            Xion\JsonResponder::responseArray($apiResponse->failure('INTERNAL-ERROR'))
        );
    } else {
        $body = file_get_contents(DIR_ROOT . '/500.html');
        Xion\HttpEmitter::emit(Xion\HttpResponse::html(
            is_string($body) ? $body : 'Internal Server Error',
            500
        ));
    }
}

/**
 * Configure whether PHP errors can be shown in HTTP responses.
 */
function configurePublicErrorDisplay(): void
{
    ini_set('display_errors', APP_DEBUG ? '1' : '0');
    ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');
}

/**
 * Register a pluggable session storage handler when SESSION_DSN is set.
 *
 * Called before session_start() so the handler is active from the first
 * request. When SESSION_DSN is empty, this is a no-op and PHP uses its
 * default file-based session storage — no behaviour change for existing
 * deployments.
 *
 * @see Nene\Xion\SessionHandlerFactory
 * @see Nene\Xion\RedisSessionHandler
 */
function configureSessionHandler(): void
{
    $handler = Xion\SessionHandlerFactory::fromDsn(SESSION_DSN);
    if ($handler !== null) {
        session_set_save_handler($handler, true);
    }
}

/**
 * Configure PHP session Cookie attributes before session_start().
 */
function configureSessionCookie(): void
{
    session_set_cookie_params([
        'lifetime' => SESSION_COOKIE_LIFETIME,
        'path' => URI_ROOT,
        'secure' => SESSION_COOKIE_SECURE,
        'httponly' => SESSION_COOKIE_HTTPONLY,
        'samesite' => SESSION_COOKIE_SAMESITE,
    ]);
}
