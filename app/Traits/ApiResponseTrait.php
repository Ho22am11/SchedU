<?php

namespace App\Traits ;

trait ApiResponseTrait {
    public function ApiResponse($data = null  , $message = null , $status = null ){

       return response()->json([
            'status' => $status ,
            'message' =>  $message ,
            'data' => $data  ] , $status) ;
        
        
     
        
    }
}