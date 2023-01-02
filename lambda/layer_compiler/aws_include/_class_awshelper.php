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
        	var $path_configfile = '/opt/config/configuration.json';
        
        	public $version; //this file version
        	public $metadata; //execution related metadata
        	public $method; // POST | GET        	
        	public $aws_domainprefix;
        	public $aws_region;
        	public $aws_function_url;
        	public $aws_requestid;

        	private $config;
        	private $salt = "TjZpB8609WkpKG5ftdvQ";

			//variable holder to set execution errors
        	public $paramerror = false;
        
        	function __construct(&$data, $paramsyntax = [])
        	{
        	    //set version for informational purposes
        	    $this->version = '1.1.0';
        	    
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
                    	'possible' => [],
                        'accepted' => [],
                        'unexpected' => [],
                        'ignored' => 0
                    ],
                    'config' => [],
                    'connections' => [],
                    'modules' => []
        		];        	    
        	    
        	    //load global config
        	    $this->metadata['config']['config_loaded'] = 0;
        	    if (file_exists($this->path_configfile)) 
        	    {
        	        if ($config = file_get_contents($this->path_configfile)) 
        	        {
                        $config = json_decode($config, 1);
                        if (isset($config) && is_array($config) && count($config)) 
                        {
                	        //assign config to private variable
                	        $this->config = $config;                            
                            
                            $this->metadata['config']['config_loaded'] = 1;
                            $elements = '';
                            foreach ($config as $k => $v) {
                                if (is_array($v)) 
                                    $elements .= ' | ' . $k . '(array)';
                                else
                                    $elements .= ' | ' . $k . '';
                            }
                            $this->metadata['config']['keys'] = trim($elements, ' |');
                            unset($elements);
                        }
        	        }
        	    }

        	    if (isset($data['headers']['host'])) 
        	    {
        	    	//environment variables
        	    	$hostparts = explode('.', $data['headers']['host']);
		            $this->aws_domainprefix = $hostparts[0];
		            $this->aws_function_url = $data['headers']['host'];
		            $this->aws_requestid = $data['requestContext']['requestId'];
        	    }
        	    
        	    //load AWS region
        	    $this->aws_region = $this->config['aws']['aws_region'];
                
        	    //retrieve body and encode it depending if its POST or GET
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
        		            //param formatting and checks that dont halt the execution
        		            if (isset($paramsyntax[$paramkey]['type']) && 
        		            	in_array($paramsyntax[$paramkey]['type'], ['integer', 'boolean', 'string', 'array', 'enum']))
        		            		$this->metadata['params']['possible'][$paramkey] = $paramsyntax[$paramkey]['type'];	

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
            			if (isset($prm['required']) && !empty($prm['required'])) {
            				if (!isset($data[$key])) {
    		                    $this->paramerror = $this->doError(sprintf('required parameter "%s" was not found', $key));
    		                    return;        					
            				}
            			}
            		}
        		}
        	}
        	
        	function paramDecrypt($data) {
        	    if (!isset($data)) return $data;
        	    
        	    if (is_array($data)) {
        	        if (count($data)) {
        	            if (isset($data['is_crypted'])) {
        	                if (isset($data['value'])) {
        	                    return $this->strDecrypt($data['value']);
        	                }
        	            }
        	        }
        	    }
        	    return $data;
        	}
        	
        	function strDecrypt($data) {
        	    $output = 'result';
        	    if ($err = $this->doExecute(${$output}, ['command' => 'doStringDecrypt', 'parameters' => ['input' => $data]])) { $this->paramerror = $this->doError($err); return; }
        	    return $result;
        	}
        	
        	
        	function strEncrypt($data) {
        	    $output = 'result';
        	    if ($err = $this->doExecute(${$output}, ['command' => 'doStringEncrypt', 'parameters' => ['input' => $data]])) { $this->paramerror = $this->doError($err); return; }
        	    return $result;
        	}
        	

        	/* Result is returning JSON encoded successful response with statusCode 200 and Body */
        	function doOk($data = '', $pagination = []) 
            {
                $this->doWrapMetadata();
                
            	//success - 200
                if (is_string($data)) $data = trim($data);
                if (empty($data)) {
                    //return as a boolean
                    $data = ['code' => $this->success_status, 'success' => sprintf('%b', $data)];
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
                            'timestamp' => time(),
                            '@metadata' => $this->metadata
                        ], JSON_FORCE_OBJECT),
                ]);
                
                return $result;
            }

            function getConfig($path) {
                if (empty($path)) return '';
                $paths = explode('->', $path);
                
                $itens = $this->config;
                foreach($paths as $ndx) {
                    $itens = $itens[$ndx];
                }
                return $this->paramDecrypt($itens);
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
                            'timestamp' => time(),
                            '@metadata' => $this->metadata
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
        	    
        	    if (isset($ps['default']))
        	        if (!empty($ps['default']))
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
			    if (!empty($data['command'])) $command = '__' . $data['command'];
			    
			    //see if we need to establish or maintain a database connection
			    if (isset($data['parameters']['connection']) && ($data['command'] != 'doEstablishSQLConnection'))
			    {
			        if ($err = $this->doExecute(${$output = 'conn'}, [
                        'command' => 'doEstablishSQLConnection', 
                        'parameters' => [
                            'connection' => $data['parameters']['connection']
                        ]
                    ])) { return $err; }
			    }
			    
	        	if (!method_exists($this, $command)) 
	        	{
	        	    $commandclass = explode('_', $data['command']);
	        	    
	        	    if (isset($commandclass[0]) && !empty($commandclass[0]) && (preg_match("/^[a-z]+$/", $commandclass[0]))) 
	        	    {
	        	        //function requested was in format <class>_<function>
	        	        $classfile = 'class_' . ($module = $commandclass[0]) . '.php';
	        	        
	        	        if (empty($this->metadata['modules'][$module]) || !is_object($this->metadata['modules'][$module]))
	        	        {
    	        	        //attemt to create class
    	        	        $includefile = '';
    	        	        
    	        	        if (file_exists($includefile = $this->config['sourcedir'] . $classfile)) { } else 
    	        	        if (file_exists($includefile = $this->config['workdir'] . $classfile)) { } else 
    	        	        if (file_exists($includefile = $this->config['includedir'] . $classfile)) { }
    	        	        
    	        	        if (!empty($includefile) && !empty($module))
    	        	        {
                                try {
                                    require_once($includefile);
                                    $this->metadata['modules'][$module] = new $module;
                                }
                                catch(exception $e) {
                                    return 'error occured when including class file: ' . print_r($e, 1);
                                }
    	        	        }
	        	        }
	        	        
	        	        if (!method_exists($this->metadata['modules'][$module], $command)) {
	        	            return 'method $' . get_class($this->metadata['modules'][$module]) . '->' . $command . '() does not exist';
	        	        }
	        	        
	        	        //we call method from sublaying class
        			    $result = json_decode(call_user_func_array(array($this->metadata['modules'][$module], $command), array($data['parameters'], $this)), 1);
        			    if ($result == '') {
        			        return 'function returned without clear result';
        			    }	        	        
	        	    }
	        	} else {
	        	    //we call method from this class
    			    $result = json_decode(call_user_func_array(array($this, $command), array($data['parameters'])), 1);
    			    if ($result == '') {
    			        return 'function returned without clear result';
    			    }	        	    
	        	}
			    
			    $successresult = NULL;
			    
                //decode body and send result
			    $result['body'] = json_decode($result['body'], 1);
			    
			    if ($result['body']['status'] == 'success')
			    {
			        if (isset($result['body']['data']['result'])) 
			        {
			            $successresult = $result['body']['data']['result'];
			            return false;
			        }
			        else if (isset($result['body']['data']['records'])) 
			        {
			            $successresult = $result['body']['data']['records'];
			            return false;
			        }
			        else if (isset($result['body']['data']['success'])) 
			        {
                        $successresult = $result['body']['data']['success'];
    			        return false;
			        }

			    } else {
			        return $result['body']['data']['message'];
			    }
			}
			
			
			/* encrypting string with key and salt */
			function __doStringEncrypt($data) 
			{
			    if (empty($data['input'])) {
			        return $this->doError('noting to encrypt');
			    }
			    
			    if (empty($this->salt)) {
			        return $this->doError('encryption SALT is not defined');
			    }
			    
			    if (empty($this->config['encryption_key'])) {
			        return $this->doError('encryption key in config file is not set');
			    }
			    
			    $encrypt_method = "AES-256-CBC";
			    $key = hash('sha256', $this->config['encryption_key']);
			    
                // iv - encrypt method AES-256-CBC expects 16 bytes - else you will get a warning
                $iv = substr(hash('sha256', $this->salt), 0, 16);
                
                $output = openssl_encrypt($data['input'], $encrypt_method, $key, 0, $iv);
                $output = base64_encode($output);
                
                return $this->doOk($output);
			}



			/* encrypting string with key and salt */
			function __doStringDecrypt($data) 
			{
			    if (empty($data['input'])) {
			        return $helper->doError('noting to decrypt');
			    }
			    
			    if (empty($this->salt)) {
			        return $this->doError('decryption SALT is not defined');
			    }
			    
			    if (empty($this->config['encryption_key'])) {
			        return $this->doError('decryption key in config file is not set');
			    }
			    
			    $encrypt_method = "AES-256-CBC";
			    $key = hash('sha256', $this->config['encryption_key']);
			    
                // iv - encrypt method AES-256-CBC expects 16 bytes - else you will get a warning
                $iv = substr(hash('sha256', $this->salt), 0, 16);
                
                $output = openssl_decrypt(base64_decode($data['input']), $encrypt_method, $key, 0, $iv);
                
                return $this->doOk($output);
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
			    function is calling AWS API Request based by endpoint path (/aws/layers/refresh)

                sample usage:
                
                    if ($err = $helper->doExecute(${$output = 'response'}, [
                        'command' => 'doAWSAPIRequest',
                        'parameters' => [
                            'region' => $aws_region,
                            'endpoint' => '/aws/lambda/layer/versions/pull/list',
                            'connection' => $conn,
                        ]
                    ])) return $helper->doError($err);                 
			*/
			protected function __doAWSAPIRequest($data) 
			{
			    //we replace this later
				if (empty($data[$tf = 'endpoint'])) {
				    return $this->doError(sprintf('required parameter is missing: [ %s ]', $tf));	
				}
				if (!$data[$tf = 'connection']) return $this->doError('required parameter missing: [' . $tf . ']');
				
				//get url for the function
                $err = $this->doExecute(${$output = 'function_url'}, [
                    'command' => 'getIdFromQuery',
                    'parameters' => [
                        'tablename' => '_aws_' . $data['region'] . '_functions',
                        'fields' => [
                            'description' => $data['endpoint'],
                            'region' => $data['region']
                        ],
                        'keycarrier' => 'function_url',
                        'singleexpected' => 1,
                        'connection' => $data['connection']
                    ]
                ]);
                
                if (!$function_url || $err) {
                    return $this->doError(sprintf('Error 404: API endpoint %s not found', $data['endpoint']));
                }

                $ch = curl_init();
                
                curl_setopt_array($ch, [
                  CURLOPT_URL => $function_url, 
                  CURLOPT_RETURNTRANSFER => 1,
                  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                  CURLOPT_CUSTOMREQUEST => 'POST',
                  CURLOPT_POSTFIELDS => json_encode($data['payload']),
                  CURLOPT_HTTPHEADER => ['Content-Type: application/json']
                ]);                
                
                $result = curl_exec($ch);
                if (curl_exec($ch) === false) {
                    return $this->doError(sprintf('CURL failed doAWSAPIRequest->%s() for endpoint %s', $data['endpoint'], $function_url));
                }                
                
                curl_close($ch);
                $result = json_decode($result, 1);
                
                if ($result['status'] == 'error') {
                    $message = "
                    Caller Function: %s
                    Message: %s
                    
                    URL Called: %s
                    EndPoint Called: %s
                    
                    Payload: %s";
                    
                    return $this->doError(sprintf($message, $this->metadata['caller']['function'], $result['data']['message'], $function_url, $data['endpoint'], print_r($data['payload'], 1)));
                }
                else
                if ($result['status'] == 'success')
                {
                    if (isset($result['data']['result']))
                        return $this->doOk($result['data']['result']);
                    else if (isset($result['data']['records']))
                        return $this->doOk($result['data']['records']);
                    else return $this->doOk($result['data']);
                }
                
                return $this->doError(sprintf('NO DATA retrieved from doAWSAPIRequest->%s() @ URL %s. Parse Error in destination file or URL incorrect?', $data['endpoint'], $function_url));
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

            private function __doEstablishSQLConnection($data) 
            {
                if (!$data['connection']) {
                    return $this->doError('no connection parameter defined');
                }
                
                if (!$this->config['connections'][$data['connection']]) {
                    return $this->doError(sprintf('config doesnt provide $config[connections][%s]', $data['connection']));
                }
                
                if (empty($this->config['connections'][$data['connection']]['hostname'])) {
                    return $this->doError(sprintf('config doesnt provide $config[connections][%s][hostname]', $data['connection']));
                }
                
                if (empty($this->config['connections'][$data['connection']]['username'])) {
                    return $this->doError(sprintf('config doesnt provide $config[connections][%s][username]', $data['connection']));
                }
                
                if (empty($this->config['connections'][$data['connection']]['password'])) {
                    return $this->doError(sprintf('config doesnt provide $config[connections][%s][username]', $data['password']));
                }
                
                $cs = [
                    'hostname' => $this->paramDecrypt($this->config['connections'][$data['connection']]['hostname']),
                    'username' => $this->paramDecrypt($this->config['connections'][$data['connection']]['username']),
                    'password' => $this->paramDecrypt($this->config['connections'][$data['connection']]['password']),
                    'database' => $this->paramDecrypt($this->config['connections'][$data['connection']]['database'])
                ];
                
                mysqli_report(MYSQLI_REPORT_ERROR);
                
                if (!$conn = mysqli_connect(
                    $cs['hostname'], 
                    $cs['username'], 
                    $cs['password'], 
                    $cs['database']
                )) {
                    return $this->doError(sprintf('unable to set MySQL connection [%s] %s', $data['connection'], $conn->error));
                }
                
                if (!is_object($conn)) {
                    return $this->doError('$conn from establisConnection is not mysql class');
                }
                
                $this->metadata['connections'][$data['connection']] = [
                    'established' => true,
                    'object' => $conn
                ];
                
                $this->conn[$data['connection']] = $conn;
                
                return $this->doOk(sprintf('connection established and can be found @ $helper->conn[%s]', $data['connection']));
            }

		}
	}

?>
