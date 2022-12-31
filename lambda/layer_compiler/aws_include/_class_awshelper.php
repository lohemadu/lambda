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
        
        	public $version; //this file version
        	public $metadata; //execution related metadata
        	public $method; // POST | GET        	

        	//request related class variables
        	public $aws_domainprefix;
        	public $aws_region;
        	public $aws_function_url;
        	public $aws_requestid;

			//variable holder to set execution errors
        	public $paramerror = false;
        
        	function __construct(&$data, $paramsyntax = NULL)
        	{ 
        	    $this->version = '1.1.0';

        	    if (isset($data['headers']['host'])) 
        	    {
        	    	//environment variables
        	    	$hostparts = explode('.', $data['headers']['host']);
		            $this->aws_domainprefix = $hostparts[0];
		            $this->aws_region = $hostparts[2];
		            $this->aws_function_url = $data['headers']['host'];
		            $this->aws_requestid = $data['requestContext']['requestId'];
        	    } else {
			        if (file_exists($inc = '/var/task/src/credentials.php')) {
			        	include($inc);
			            $this->aws_region = $aws_region;
			        }
        	    }
       	    
        	    //retrieve body and encode it
                if (!empty($data['requestContext']['http']['method'])) 
                {
                    $this->method = $data['requestContext']['http']['method'];
                    //we only accept POST for now
                    if ($this->method == 'POST')
                    {
                        if (!empty($data['body'])) {
                            //base64 test
                	        if ((bool)preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $data['body']))
                	            $data['body'] = base64_decode($data['body']);
                	            
                            $data = json_decode($data['body'], 1);
                        }                            
                    } 
                    else
                    if ($this->method == 'GET')
                    {
                        if (!empty($data['rawQueryString'])) {
                            parse_str($data['rawQueryString'], $data);
                        }
                    } else
                    {
                        //method is not POST | GET
                        return $this->paramerror = $this->doError(
                            sprintf('Method %s is not Allowed.', $this->method)
                        );                            
                    }                    
                }
                

        		//metadata skeleton
        		$this->metadata = [
        			'caller' => [
        			    'function' => debug_backtrace()[1]['function']
                    ],
                    'timer' => [
                        'started_at_microtime' => intval(explode(' ', microtime())[1] * 1E3) + intval(round(explode(' ', microtime())[0] * 1E3))
                    ],
                    'paramsyntax' => $paramsyntax,
                    'params' => [
                        'accepted' => [],
                        'unexpected' => [],
                        'ignored' => 0
                    ]
        		];
        		
        		//loop and clean parameters
        		foreach ($data as $paramkey => $paramvalue)
        		{
        		    if (
        		        //allowed parameters consist only a-z and 0-1 and -
                        preg_match('/^[a-z0-9\-]+$/', $paramkey) 
        		        && 
        		        //leading minus
                        $paramkey[0] != '-' 
                        &&
                        //trailing minus
                        $paramkey[strlen($paramkey) - 1] != '-'
                        &&
                        //not empty
                        !empty(strlen($paramkey)))
                    {
                        //if parameter is accepted or unexpected
                        if (isset($paramsyntax[$paramkey])) 
                        {
        		            $this->metadata['params']['accepted'][$paramkey] = 1;
        		            
        		            $ps = $paramsyntax[$paramkey];
        		            
        		            //param formatting and checks that dont halt the execution
        		            if (!in_array($ps['type'], ['integer', 'boolean', 'string', 'array', 'enum']))
        		            {
        		                return $this->paramerror = $this->doError(
                                    sprintf('Required parameter syntax "type" not found for parameter "%s" or is not one of the following [ integer | boolean | string | array | enum ]', $paramkey)
                                );
        		            }
        		            
        		            if ($ps['type'] == 'integer') 
        		            {
        		                $this->doIntegerParameterTypeTest($data[$paramkey], $paramkey, $ps);
        		            } 
        		            else if ($ps['type'] == 'boolean')
        		            {
        		                $this->doBooleanParameterTypeTest($data[$paramkey], $paramkey, $ps);
        		            }
        		            else if ($ps['type'] == 'string') 
        		            {
        		                $this->doStringParameterTypeTest($data[$paramkey], $paramkey, $ps);
        		            }
        		            else if ($ps['type'] == 'array') 
        		            {
        		                $this->doArrayParameterTypeTest($data[$paramkey], $paramkey, $ps);
        		            }
        		            else if ($ps['type'] == 'enum') 
        		            {
        		                $this->doEnumParameterTypeTest($data[$paramkey], $paramkey, $ps);
        		            }
        		            
        		            if (!empty($this->paramerror)) return;
        		            
                        } else {
                            //move parameter to unexpected parameters list
                            $this->metadata['params']['unexpected'][$paramkey] = 1;
                            unset($data[$paramkey]);    
                        }
        		    }
        		    else
        		    {
        		        //move parameter to ignored counter
        		        //happens if parameter uses characters that are not allowed in parameter
        		        $this->metadata['params']['ignored']++;
        		        unset($data[$paramkey]);
        		    }
        		}

        		//find parameters that doesnt exist in $data
        		if (is_array($paramsyntax))
        		{
            		foreach ($paramsyntax as $key => $prm) {
            			if ($prm['required']) {
            				if (!isset($data[$key])) {
    		                    $this->paramerror = $this->doError(sprintf('required parameter "%s" was not found', $key));
    		                    return;        					
            				}
            			}
            		}
        		}
        	}


        	/* Result is returning JSON encoded successful response with statusCode 200 and Body */
        	function doOk($data = '', $pagination = []) 
            {
                $this->doWrapMetadata();
                
            	//success - 200
                if (is_string($data)) $data = trim($data);
                if (empty($data)) {
                    //return as a boolean
                    $data = ['code' => $this->success_status, 'success' => 1];
                } else
                if (is_object($data)) {
                    $data = ['code' => $this->success_status, 'result' => $data];
                } else                
                if (!is_array($data)) 
                {
                    //result as a string message
                    $data = ['code' => $this->success_status, 'result' => $data];
                } else
                {
                    //result as an array
                    $return = $data;
                    foreach ($pagination as $k => $v) {
                        if (in_array($k, ['perpage', 'page', 'totalpages', 'totalrecords', 'count']))
                            $return[$k] = $v;
                    }
                    
                	$data = ['code' => $this->success_status, 'records' => $return];
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


            function hasElements($input) {
            	return (is_array($input) && is_countable($input) && count($input));
            }
        
        
            /* Result is returning JSON encoded error message with statusCode 400 and Body */
            function doError($data = '') 
            {
                $this->doWrapMetadata();

            	//error - 400
                if (is_string($data)) $data = trim($data);
                if (empty($data)) {
                    //return as a boolean
                    $data = ['code' => $this->error_bad_request, 'error' => 1];
                } else
                if (is_object($data)) {
                    $data = ['code' => $this->error_bad_request, 'message' => $data, 'methods' => get_class_methods($data)];
                } else                
                if (!is_array($data)) 
                {
                    //result as a string message
                    $data = ['code' => $this->error_bad_request, 'message' => $data];
                } else
                {
                	//result as an array
                	$data = ['code' => $this->error_bad_request, 'message' => $data];
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
            
            //end execution without encoding result
            function doPlain($data)
            {
                $this->doWrapMetadata();
                return $data;
            }
            
            //function is doing some last minute calculations for stats and timeout detection purposes
            private function doWrapMetadata()
            {
                $this->metadata['timer']['ended_at_microtime'] = intval(explode(' ', microtime())[1] * 1E3) + intval(round(explode(' ', microtime())[0] * 1E3));
                //calculate execution time
                $this->metadata['timer']['execution_time'] = $this->metadata['timer']['ended_at_microtime'] - $this->metadata['timer']['started_at_microtime'];
            }

            //perform type test to enum.
        	/*
        	    required parameter syntax key is:
        	        options => <array> of possible options that value must fall into
        	        
        	    allowed parameter syntax keys are:  
        	        mustexist => <boolean> - value must be one of the followings
        	        default => <string> - NB! fails in case set and not one of the options
            */
            
            function doEnumParameterTypeTest(&$input, $parameter, $ps = []) 
            {
                $input = strtoupper($input);
                
                if (!isset($ps['options']) || !is_array($ps['options'])) {
                    $this->paramerror = $this->doError(sprintf('required parameter "%s" is expecting enum "options" but not found', $parameter));
                    return;
                }
                
                if ($ps['default'])
                {
                    $defaultfound = false;
                    foreach ($ps['options'] as $enumkey) {
                        $enumkey = strtoupper($enumkey);
                        if ($ps['default'] == $enumkey) $defaultfound = true;
                    }
                    if (!$defaultfound) {
                        $this->paramerror = $this->doError(sprintf('required parameter "%s" default enum [ %s ] is not one of the following [ %s ]', $parameter, $ps['default'], implode(' | ', $ps['options'])));
                        return;                        
                    }
                }
                
                $found = false;
                foreach ($ps['options'] as $enumkey) {
                    $enumkey = strtoupper($enumkey);
                    if ($input == $enumkey) $found = true;
                }
                if (!$found)
                {
                    if (!empty($ps['mustexist'])) {
                        $this->paramerror = $this->doError(sprintf('required parameter "%s" [ %s ] is not one of the following [ %s ]', $parameter, $input, implode(' | ', $ps['options'])));
                        return;
                    }
                    
                    if (empty($ps['default'])) {
                        $this->paramerror = $this->doError(sprintf('required parameter "%s" has no default from enum options [ %s ]', $parameter, implode(' | ', $ps['options'])));
                        return;
                    }                        
                    
                    $input = $ps['default'];
                }
                
                

            }                
            
            //perform type test to array.
        	/*
        	    allowed parameter syntax keys are:  
        	        default => <json> - default array JSON in case of empty or not in case of array
        	        min-count => <integer> - fail in case of less elements
        	        require-keys => <string> - comma separated list of array keys that need to exist
            */
            
            function doArrayParameterTypeTest(&$input, $parameter, $ps = [])
            {
                if (!is_array($input) || empty($input)) {
                    if (isset($ps['default'])) {
                        $input = json_decode($ps['default'], 1);
                    } else {
                    	if (isset($ps['required'])) {
                    		$this->paramerror = $this->doError(sprintf('array $data[%s] is expected but not found', $parameter, $key));
                    		return;
                    	}
                    }
                    if (!is_array($input) || empty($input)) 
                    {
						if (isset($ps['required'])) 
						{
                    		$this->paramerror = $this->doError(sprintf('array $data[%s] is expected but not found', $parameter, $key));
                    		return;
                    	}
                        $input = [];
                    }
                }
                
                if (isset($ps['min-count']) && (count($input) < $ps['min-count'])) {
                    $this->paramerror = $this->doError(sprintf('required parameter "%s" is expecting %d elements but %d found', $parameter, $ps['min-count'], count($input)));
                    return;
                }
                
                if (!is_array($input)) {
                    $this->paramerror = $this->doError(sprintf('required parameter "%s" is expecting to be array type but its not. Use valid "default" JSON', $parameter));
                    return;
                }
                
                if (!empty($ps['require-keys'])) {
                    $requiredkeys = explode(',', $ps['require-keys']);
                    foreach ($requiredkeys as $key) {
                        $key = trim($key);
                        if (!isset($input[$key])) {
                            $this->paramerror = $this->doError(sprintf('array key $%s[%s] was expected but not found', $parameter, $key));
                            return;
                        }
                    }
                }
            }
            
            //perform type test to boolean.
        	/*
        	    allowed parameter syntax keys are:
        	        fail-if-empty => <1> to fail on '' inputs
        	        default => boolean to replace in case of ''
        	        fail-if-false => <boolean> to fail if parameter is set false
        	        fail-if-true => <boolean> to fail if parameter is set true
        	*/
        	
        	function doBooleanParameterTypeTest(&$input, $parameter, $ps = []) {
        	    if ($input == '' && $ps['fail-if-empty']) {
        	        $this->paramerror = $this->doError(sprintf('required parameter "%s" is not one of the following [ true | false | 1 | 0 ]', $parameter));
        	    }
        	    
        	    if ($input == '' && isset($ps['default'])) {
        	        $input = sprintf('%b', $ps['default']);
        	    }
        	    
        	    if ((!$input) && isset($ps['fail-if-false'])) {
        	        $this->paramerror = $this->doError(sprintf('required parameter "%s" is false and "fail-if-false" flag is set', $parameter));
        	    }
        	    
        	    if (($input) && isset($ps['fail-if-true'])) {
        	        $this->paramerror = $this->doError(sprintf('required parameter "%s" is true and "fail-if-true" flag is set', $parameter));
        	    }   
        	    
        	    $input = sprintf('%b', $ps['default']);
        	}
            
        	//perform type test to integer.
        	/*
        	    allowed parameter syntax keys are:
        	        fail-if-empty => <1> to fail on '' inputs
        	        default => int to replace in case of 0
        	        min-value => int to perform minimum value test
        	        max-value => int to perform maximum value test
        	        required => <1> to return error in case integer = 0
        	*/                
        	function doIntegerParameterTypeTest(&$input, $parameter, $ps = []) {
        	    //return error if empty
        	    if ($input == '' && $ps['fail-if-empty']) {
        	        $this->paramerror = $this->doError(sprintf('required parameter "%s" is empty', $parameter));
        	    }
        	    
        	    $input = sprintf('%d', $input);
        	    
        	    //if input = 0 we replace it with default
        	    if ($input == 0 && $ps['default']) {
        	        $input = sprintf('%d', $ps['default']);
        	    }
        	    
        	    //in case of value less than minimum value we set to minimum value
        	    if (isset($ps['min-value']) && $ps['min-value'] != '' && $input < $ps['min-value']) {
        	        $input = $ps['min-value'];
        	    }
        	    
        	    //in case of value bigger than maximum value we set to maximum value
        	    if (isset($ps['max-value']) && $ps['max-value'] != '' && $input > $ps['max-value']) {
        	        $input = $ps['max-value'];
        	    }
        	    
        	    //in case of zero we return error
        	    if ($input == 0 && $ps['required']) {
        	        $this->paramerror = $this->doError(sprintf('required parameter "%s" is empty', $parameter));
        	    }            	    
        	    
        	}


            /*
                get the id row from provided parameters
                
                sample usage:
                if ($err = $test->doExecute(${$output = 'fk_layer_id'}, [
                    'command' => 'getIdFromQuery',
                    'parameters' => [
                        'tablename' => '_aws_' . $aws_region . '_layers',
                        'fields' => [
                            'aws_layer_name' => $layername
                        ],
                        'keycarrier' => 'aws_layer_id',
                        'singleexpected' => 1,
                        'connection' => $conn
                    ]
                ])) return $helper->doError($err);                
            */
            protected function __getIdFromQuery($data) {
                //kaob ära, kui me saame kasutada parameetri kontrollimise funktsiooni
				if (!$data[$tf = 'tablename']) return $this->doError('required parameter missing: [' . $tf . ']');
	            if (!$data[$tf = 'connection']) return $this->doError('required parameter missing: [' . $tf . ']');
	            if (!count($data[$tf = 'fields'])) return $this->doError('array is not defined: [' . $tf . ']');
	            
	            foreach ($data['fields'] as $key => $value) {
	                $wherequery[] = sprintf('`%s` = \'%s\'', $key, $value);
	            }
	            $where = implode(' AND ', $wherequery);
	            if (!$where) {
	                return $this->doError('where clause is not defined for count query.');
	            }				
	            
	            $query = sprintf("SELECT * FROM `%s` WHERE %s", $data['tablename'], $where);
	            if (!$res = $data['connection']->query($query)) {
	                return $this->doError($data['connection']->error);
	            }
	            
	            if (empty(mysqli_num_rows($res))) {
	                return $this->doError('no records retrieved from $awshelper->getIdFromQuery()');
	            }
	            
	            if (mysqli_num_rows($res) > 1 && !empty($data['singleexpected'])) {
	                return $this->doError(sprintf('$awshelper->getIdFromQuery() returned more than one row: (%d rows)', mysqli_num_rows($res)));
	            }
	            
	            $row = mysqli_fetch_assoc($res);
	            if (!isset($row[$data['keycarrier']])) {
	                return $this->doError(sprintf('$awshelper->getIdFromQuery() result didnt consist field %s', $data['keycarrier']));
	            }
	            
	            $value = $row[$data['keycarrier']];
	            if (!$value) {
	                return $this->doError(sprintf('$awshelper->getIdFromQuery() result returned nothing', $data['keycarrier']));
	            }
	            
                return $this->doOk($value);
            }
        	
        	
        	//perform type test to string.
        	/*
        	    allowed parameter syntax keys are:
        	        skip-trim => <1> to avoid trimming
        	        strip-not-matched => <pattern> to strip all chararacters not in range
        	        htmlentities => <1> to convert special characters to html entities
        	        addslashes => <1> to perform addslashes() php function
        	        length => <int> to require input length
        	        default => <string> to replace input in case of empty parameter
        	        required => <1> to return error in case of empty string
        	*/
        	private function doStringParameterTypeTest(&$input, $parameter, $ps = []) 
        	{
        	    //in case of skip-trim flag we dont strip the input string
        	    //strip is by-default behaviour
        	    if (empty($ps['skip-trim'])) 
        	    {
        	        $input = trim($input);
        	    }
        	    
        	    //strip-not-matched preg_match returns only characters in given range
        	    if (!empty($ps['strip-not-matched']))
        	    {
        	        $input_remainer = '';
        	        $pattern = $ps['strip-not-matched'];
        	        for ($index = 0; $index < strlen($input); $index++) {
        	            $char = $input[$index];
        	            if (preg_match('/^' . $pattern . '+$/', $char))
        	                $input_remainer .= $char;
        	        }
        	        $input = $input_remainer;
        	    }

        	    //require string length
        	    if (!empty($ps['length'])) {
        	        if (strlen($input) != $ps['length'])
        	            $this->paramerror = $this->doError(sprintf('required parameter "%s" is not length of %d but %d instead', $parameter, $ps['length'], strlen($input)));
        	    }
        	    
        	    //convert special chars to htmlentities
        	    if (!empty($ps['htmlentities'])) {
        	        $input = htmlentities($input);
        	    }
        	    
        	    //add slashes if required
        	    if (!empty($ps['addslashes'])) {
        	        $input = addslashes($input);
        	    }
        	    
        	    //set default if empty
        	    if ($input == '' && $ps['default']) {
        	        $input = $ps['default'];
        	    }
        	    
        	    //return error if empty
        	    if ($input == '' && $ps['required']) {
        	        $this->paramerror = $this->doError(sprintf('required parameter "%s" is empty', $parameter));
        	    }
        	}


			//wrapper to call all this class private functions with ['command' => 'funcname'()]
			/*
				returns result in case of error only.
				if no error, $successresult will return the message from the function
			*/			
			function doExecute(&$successresult, $data) 
			{
			    if (!empty($data['command'])) $data['command'] = '__' . $data['command'];
			    
	        	if (!method_exists($this, $data['command']))
	        	    return 'method $' . get_class($this) . '->' . $data['command'] . '() does not exist';
			    
			    $successresult = NULL;
			    $result = json_decode(call_user_func_array(array($this, $data['command']), array($data['parameters'])), 1);
			    if (empty($result)) {
			        return 'function returned without clear result';
			    }
			    
			    $result['body'] = json_decode($result['body'], 1);
			    
			    if ($result['body']['status'] == 'success')
			    {
			        if (isset($result['body']['data']['result'])) 
			        {
			            $successresult = $result['body']['data']['result'];
			            return false;
			        }
			        if (isset($result['body']['data']['records'])) 
			        {
			            $successresult = $result['body']['data']['records'];
			            return false;
			        }

			    } else {
			        return $result['body']['data']['message'];
			    }
			} 


			/*
				all protected functions must be called through doExecute,
				even if called from inside this class


				inserting a new record to table
				sample usage:

		        if ($err = $helper->doExecute(${$output = 'query'}, [
		            'command' => 'doBuildInsertQuery',
		            'parameters' => [
		                'tablename' => '_aws_layers',
		                'fields' => [
		                    'function_id' => 1,
		                    'function_data' => 'hello',
		                    'third' => 'yeah'
		                ]
		            ]
		        ])) return $helper->doError($err);
		        return $helper->doOk($query);
			*/
	        protected function __doBuildInsertQuery($data) 
	        {
	            //kaob ära, kui me saame kasutada parameetri kontrollimise funktsiooni
				if (!$data[$tf = 'tablename']) return $this->doError('required parameter missing: [' . $tf . ']');
	        	
	            $packed = array();
	            foreach($data['fields'] as $k => $v) {
	                if (!is_array($v)) $packed[$k] = addslashes($v);
	            }
	            
	            $key = array_keys($packed);
	            $val = array_values($packed);
	            $query = "INSERT INTO `" . $data['tablename'] . "` (`" . implode('`, `', $key) . "`) " . "VALUES ('" . implode("', '", $val) . "')";
	            
	            //for mysql functions we use !!!NOW()!!!
	            //remove escaping
	            $query = str_replace('!!!\'', '', str_replace('\'!!!', '', $query));
	            
	            return $this->doOk($query);
	        }

	        /*
				function will update recordset based on given keys

	        	sample usage:

		        if ($err = $helper->doExecute(${$output = 'query'}, [
		            'command' => 'doBuildInsertQuery',
		            'parameters' => [
		                'tablename' => '_aws_layers',
		                'fields' => [
		                    'item_id' => 12,
		                    'fk_order_id' => 2,
		                    'item_contents' => 'some item'
		                ],
		                'keys' => [
		                	'item_id',
		                	'fk_order_id'
		                ]
		            ]
		        ])) return $helper->doError($err);
		        return $helper->doOk($query);
	        */

			protected function __doBuildUpdateQuery($data)
			{
				//construct update query based on data
			    //param checks will be deprecated from this function soon
				if (!$data[$tf = 'tablename']) return $this->doError('required parameter missing: [' . $tf . ']');
				
	            foreach ($data['keys'] as $void => $key) {
	                if (!isset($data['fields'][$key])) return $this->doError('fields[' . $key . '] is not defined in input params');
	                $wherequery[] = sprintf('`%s` = \'%s\'', $key, addslashes($data['fields'][$key]));
	                unset($data['fields'][$key]);
	            }
	            $where = implode(' AND ', $wherequery);
	            if (!$where) {
	                return $this->doError('where clause is not defined for count query.');
	            }
	            
	            $packed = array();
	            foreach($data['fields'] as $k => $v) {
	            	if ($v != '') $v = addslashes($v);	            	
	                if (!is_array($v)) $packed[] = sprintf("`%s` = '%s'", addslashes($k), $v);
	            }
	            
	            if (!count($packed)) {
	                return $this->doError('no parameters to update in SQL query');
	            }
	            
	            $query = sprintf("UPDATE `%s` SET %s WHERE %s", $data['tablename'], implode(', ', $packed), $where);
	            
	            //for mysql functions we use !!!NOW()!!!
	            //remove escaping
	            $query = str_replace('!!!\'', '', str_replace('\'!!!', '', $query));

	            return $this->doOk($query);
			}


			/*
				function will either update or delete the recordset

				sample usage:

                //enter or update row in _aws_layers_table
                if ($err = $test->doExecute(${$output = 'query'}, [
                    'command' => 'doBuildAndMakeInsertOrUpdateQuery',
                    'parameters' => [
                        'tablename' => '_aws_layers',
                        'fields' => [
                        	'aws_layer_name' => 'my-layer',
                        	'version' => 12
                        ],
                        'keys' => [
                            'aws_layer_name'
                        ],
                        'connection' => $conn
                    ]
                ])) return $helper->doError($err);				
			*/
	        protected function __doBuildAndMakeInsertOrUpdateQuery($data)
	        {
	            
	            if (!$data[$tf = 'connection']) return $this->doError('required parameter missing: [' . $tf . ']');
	            if (!count($data[$tf = 'keys'])) return $this->doError('array is not defined: [' . $tf . ']');
	            
	            foreach ($data['keys'] as $void => $key) {
	                if (!isset($data['fields'][$key])) return $this->doError('fields[' . $key . '] is not defined in input params');
	                $wherequery[] = sprintf('`%s` = \'%s\'', $key, addslashes($data['fields'][$key]));
	            }
	            $where = implode(' AND ', $wherequery);
	            if (!$where) {
	                return $this->doError('where clause is not defined for count query.');
	            }
	            
	            /* depending on keys update or insert command will be chosen */
	            $countquery = sprintf("SELECT count(*) as `cnt` FROM `%s` WHERE %s", $data['tablename'], $where);
	            if (!$res = $data['connection']->query($countquery)) {
	                return $this->doError($data['connection']->error);
	            }
	            
	            if (!mysqli_num_rows($res)) {
	                return $this->doError('count query gave no rows, there should be exactly one');
	            }
	            
	            $count = ($row = mysqli_fetch_assoc($res))['cnt'];

	            if ($count) 
	            {
	                //update record in dataset
                    if ($err = $this->doExecute(${$output = 'query'}, [
                        'command' => 'doBuildUpdateQuery',
                        'parameters' => [
                            'tablename' => $data['tablename'],
                            'fields' => $data['fields'],
                            'keys' => $data['keys']
                        ]
                    ])) return $this->doError($err);  

	                if (!$success = $data['connection']->query($query)) {
	                    return $this->doError('update sql error: ' . $data['connection']->error);
	                }
	                
	                return $this->doOk('recordset updated');
	                
	            } else {
	                
	                //insert new record into dataset
                    if ($err = $this->doExecute(${$output = 'query'}, [
                        'command' => 'doBuildInsertQuery',
                        'parameters' => [
                            'tablename' => $data['tablename'],
                            'fields' => $data['fields']
                        ]
                    ])) return $helper->doError($err);    	                
	                
	                if (!$success = $data['connection']->query($query)) {
	                    return $this->doError('insert sql error: ' . $data['connection']->error);
	                }
	                
	                return $this->doOk('new recordset inserted');
	            }
	        }
	        
	        
            //get all records from the sql query
            /*
                sample usage:
                if ($err = $helper->doExecute(${$output = 'result'}, [
                    'command' => 'getQueryResultRecordset',
                    'parameters' => [
                        'query' => $query,
                        'keyholder' => 'function_name'
                        'connection' => $conn
                    ]
                ])) return $helper->doError($err);
            */
            
            private function __getQueryResultRecordset($data) {
                if (!$data[$tf = 'connection']) return $this->doError('required parameter missing: [' . $tf . ']');
                if (!$data[$tf = 'keyholder']) return $this->doError('required parameter missing: [' . $tf . ']');
                if (!$data[$tf = 'query']) return $this->doError('required parameter missing: [' . $tf . ']');
                
                if (!$res = $data['connection']->query($data['query'])) {
                    return $this->doError($data['connection']->error);
                }
                
                $result = [];
                
                //no records return
                if (!mysqli_num_rows($res)) {
                    if (isset($data['no-records-allowed']) && $data['no-records-allowed'] == false) {
                        return $this->doError('query returned zero results');
                    } else {
                        return $this->doOk('no records found.');
                    }                    
                }
                
                while ($row = mysqli_fetch_assoc($res))
                {
                    //no keyholder field in resultset
                    if (!isset($row[$data['keyholder']])) {
                        return $this->doError(sprintf('keyholder "%s" is missing from resultset: [ %s ]', $data['keyholder'], implode(' | ', array_keys($row) )));
                    }
                    
                    if (!empty($result[$row[$data['keyholder']]])) {
                        return $this->doError(sprintf('keyholder "%s" with value "%s" is already existing in result. please choose unique id from resultset: [ %s ]', $data['keyholder'], $row[$data['keyholder']], implode(' | ', array_keys($row) )));
                    }
                    
                    $result[$row[$data['keyholder']]] = $row;
                }
                
                return $this->doOk($result);
            }

		}



		}
	}

?>
