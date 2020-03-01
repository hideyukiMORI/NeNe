<?php
namespace Nene\Func;

/**
 * AYANE : ayane.co.jp
 * powerd by NENE.
 *
 * @author hideyuki MORI
 */

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
    final public static function inputPostJsonToArray() : array
    {
        $postJson   = file_get_contents('php://input');
        $jsonArray  = json_decode($postJson, true);
        $jsonArray  = is_array($jsonArray) ? $jsonArray : array();
        return $jsonArray;
    }



    /**
     * Output Array to Json.
     * Output the argument that was casted array to json.
     *
     * @param array $jsonArray Array that you want to convert to JSON format.
     * @return void
     */
    final public static function outputArrayToJson(array $jsonArray, string $style = 'jsonp', string $callback = 'jsonCallback', bool $session = false)
    {
        $responseArray = [
            'Result' => true,
            'Data'   => $jsonArray
        ];
        if($style == 'jsonp') {
            if($callback == NULL || !$callback) {
                $callback = 'jsonCallback';
            }
            $json = json_encode($responseArray, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            header("Content-type: application/x-javascript");
            echo $callback.'('.$json.')';
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
     * @param string $errorCode     Error code.
     * @param string $errorMessage  Error message.
     * @return void
     */
    final public static function outputErrorInJson(string $errorCode, string $errorMessage)
    {
        $responseArray = [
            'Resul' => false,
            'Error' => [
                'ErrorCode' => $errorCode,
                'ErrorMessage' => $errorMessage
            ]
        ];
        $json = json_encode($responseArray);
        header('Content-Type: application/json; charset=utf-8');
        echo $json;
        exit();
    }
}
