<?php

	/*
		Bootstrap File for AWS Lambda
	*/

	if (!class_exists('awshelper'))
	{
		class awshelper 
		{			
			var $error_bad_request = 400;
			var $success_status = 200;

			public $version;
			public $started;

			function __construct($data)
			{
				$this->version = 1;
				$this->started = time();
			}

			/* Result is returning JSON encoded successful response with statusCode 200 and Body */
			function doOk($data = '', $pagination = []) 
            {
            	//success - 200
                if (empty($data)) {
                    //return as a boolean
                    $data = ['success' => true];
                }
                else if (!is_array($data)) 
                {
                    //result as a string message
                    $data = ['message' => $data];
                } else {
                    //result as an array
                    foreach ($pagination as $k => $v) {
                        if (in_array($k, ['perpage', 'page', 'totalpages', 'totalrecords', 'count']))
                            $return[$k] = $v;
                    }
                    
                    $return['records'] = $data;
                    $data = $return;
                }
                
                $result = json_encode([
                    'statusCode' => $this->success_status,
                    'body' => json_encode(
                        [
                            'status' => "success",
                            'data' => $data,
                            'timestamp' => time()
                        ], JSON_FORCE_OBJECT),
                ]);
                
                return $result;
            }


            /* Result is returning JSON encoded error message with statusCode 400 and Body */
            function doError($data = '') 
            {
            	//bad request error - 400
                if (empty($data)) {
                    //return as a boolean
                    $data = ['code' => $this->error_bad_request, 'error' => 1];
                } else if (!is_array($data)) 
                {
                    //result as a string message
                    $data = ['code' => $this->error_bad_request, 'message' => $data];
                } else {
                    $data['code'] = $this->error_bad_request;
                }             
                
                $result = json_encode([
                    'statusCode' => $this->error_bad_request,
                    'body' => json_encode(
                        [
                            'status' => "error",
                            'data' => $data,
                            'timestamp' => time()
                        ], JSON_FORCE_OBJECT),
                ]);
                
                return $result;                
            }



		}
	}

?>
