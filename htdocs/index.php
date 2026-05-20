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

Xion\EnvLoader::loadIfExists(dirname(__DIR__) . '/.env');
Xion\Initialize::init();

configurePublicErrorDisplay();
configureSessionCookie();
session_cache_expire(180);
date_default_timezone_set('Asia/Tokyo');
session_start();

$dispatcher = new Xion\Dispatcher();
try {
    $dispatcher->dispatch();
} catch (Xion\HttpTermination $termination) {
    Xion\HttpEmitter::emit($termination->response());
} catch (Xion\DomainException $domainException) {
    $apiResponse = new Xion\ApiResponse();
    Xion\HttpEmitter::emit(
        Xion\JsonResponder::responseArray($apiResponse->failure($domainException->errorCode()))
    );
} catch (\Throwable $throwable) {
    Xion\Log::getInstance('error')->error('Unhandled application error.', ['exception' => $throwable]);
    Xion\HttpEmitter::emit(Xion\HttpResponse::text(
        APP_DEBUG ? $throwable->getMessage() : 'Internal Server Error',
        500
    ));
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
