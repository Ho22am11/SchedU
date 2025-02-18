<?php

namespace App\Helpers;

class ApiResponce
{
    public static function sendResponse($code=200, $msg, $data)
    {
        $response = [
            'code' => $code,
            'message' => $msg,
            'data' => $data
        ];

        return response()->Json($response, $code);
    }
}