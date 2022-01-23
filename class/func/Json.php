<?php

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * PHP Version >= 7.4
 *
 * @package   AYANE
 * @author    hideyukiMORI <info@ayane.co.jp>
 * @copyright 2021 AYANE
 * @license   https://choosealicense.com/no-permission/ NO LICENSE
 * @link      https://ayane.co.jp/
 */

declare(strict_types=1);

namespace Nene\Func;

/**
 * Common functions related to Json
 */
class Json
{
    /**
     * CONSTRUCTOR.
     */
    public function __construct()
    {
    }

    /**
     * Input POST Json to Array.
     *
     * @return array Return the input POST data that was casted json to array.
     */
    final public static function inputPostJsonToArray(): array
    {
        $postJson   = file_get_contents('php://input');
        $jsonArray  = json_decode($postJson, true);
        return is_array($jsonArray) ? $jsonArray : [];
    }

    /**
     * Output Array to Json.
     * Output the argument that was casted array to json.
     *
     * @param array   $jsonArray Array that you want to convert to JSON format.
     * @param string  $format    Specified format.
     * @param string  $callback  Callback function name.
     * @param boolean $session   Whether authentication is required.
     *
     * @return void
     */
    final public static function outputArrayToJson(
        array $jsonArray,
        string $format = 'jsonp',
        string $callback = 'jsonCallback',
        bool $session = false
    ): void {
        $responseArray = [
            'Result' => true,
            'Data'   => $jsonArray
        ];
        if ($format == 'jsonp') {
            if ($callback == null || !$callback) {
                $callback = 'jsonCallback';
            }
            $json = json_encode($responseArray, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            header("Content-type: application/x-javascript");
            echo $callback . '(' . $json . ')';
        } else {
            $json = json_encode($responseArray);
            header('Content-Type: application/json; charset=utf-8');
            echo $json;
        }
        exit();
    }

    /**
     * Output Error in JSON format.
     *
     * @param string $errorCode    Error code.
     * @param string $errorMessage Error message.
     *
     * @return void
     */
    final public static function outputErrorInJson(string $errorCode, string $errorMessage): void
    {
        $responseArray = [
            'Result' => false,
            'Error'  => [
                'ErrorCode'    => $errorCode,
                'ErrorMessage' => $errorMessage
            ]
        ];
        $json = json_encode($responseArray);
        header('Content-Type: application/json; charset=utf-8');
        echo $json;
        exit();
    }
}
