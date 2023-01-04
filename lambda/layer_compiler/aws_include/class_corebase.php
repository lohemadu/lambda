<?php

	class corebase 
	{
		//declare protected inheritable variables
		private $version;
		
		//protected variables
		protected $configfile = '/opt/config/configuration.json';
		protected $metadata = [];
		
		//make protected later!
		protected $config = [];
		protected $salt = "TjZpB8609WkpKG5ftdvQ";
		
		//public variables
	    public $error_bad_request = 400;
		public $success_status = 200;
		public $task = [];
        public $aws_region;

		function __construct() 
		{
			$this->version = '1.1.0.core';
		}
		
		//PUBLIC SCOPE METHOD DECLARATIONS
		
		/* by entering input parameters we return if element is array and can be forlooped */
		public function hasElements($variable = NULL) {
			return (isset($variable) && is_array($variable) && count($variable));
		}
		
		public function hasContent($variable = NULL) {
			return (isset($variable) && !empty($variable));
		}
		
		//INHERITABLE SCOPE METHOD DECLARATIONS
		
		/* function is preparing metadata for displaying and registering its base structure */
		protected function constructMetadata($paramsyntax = []) 
		{
			//get starttime
			try {
				$started_at_microtime = intval(explode(' ', microtime())[1] * 1E3) + intval(round(explode(' ', microtime())[0] * 1E3));
			} catch(Exception $e) { 
				$started_at_microtime = NULL;
			}
			
			//construct base metadata structure
            $this->metadata = [
                'caller' => [
                    'function' => debug_backtrace()[2]['function'] ?? 'direct'
                ],
                'timer' => [
                    'started_at_microtime' => $started_at_microtime ?: '0'
                ],
                'paramsyntax' => $paramsyntax,
                'params' => [
                    'accepted' => [],
                    'unexpected' => [],
                    'ignored' => 0
                ],
                'config' => [],
                'connections' => [],
                'modules' => []
            ]; 			
		}
		
		/* reading config from global config file */
		protected function readConfig($config = []) 
		{
			if ($this->hasElements($config)) 
			{
                $this->config = $config;
                $this->metadata['config']['config_loaded'] = 1;				
			}
			else 
			{
				$this->metadata['config']['config_loaded'] = 0;
				if (file_exists($this->configfile)) 
				{
                    if ($config = file_get_contents($this->configfile)) 
                    {
                        $config = json_decode($config, 1);
                        if ($this->hasElements($config)) 
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
                        else
                        {
                        	$this->config = [];
                        }
                    }
                }
			}
		}
		
		/* reading headers from AWS Lambda Request*/
		protected function readHeaders($data) 
		{
            if ($this->hasContent(@$data['headers']['host'])) 
            {
                //environment variables
                $hostparts = explode('.', $data['headers']['host']);
                
                if ($this->hasContent($hostparts[0])) {
                	$this->task['domain_prefix'] = $hostparts[0];
                }
                if ($this->hasContent($hostparts[2])) {
                	$this->task['region'] = $hostparts[2];
                }                
                if ($this->hasContent($data['headers']['host'])) {
                	$this->task['function_url'] = $data['headers']['host'];
                }
                if ($this->hasContent($data['requestContext']['requestId'])) {
                	$this->task['requestid'] = $data['requestContext']['requestId'];
                }
            }
            
            //load AWS region
            if ($this->hasContent($this->config['aws']['aws_region'])) {
            	$this->aws_region = $this->config['aws']['aws_region'];
            } else {
            	//region failover
            	if ($this->hasContent($this->task['region'])) {
            		$this->aws_region = $this->task['region'];
            	} else return $this->paramerror = $this->doError(
                    sprintf('WE REQUESTED VARIABLE FROM CONFIG: $this->config["aws"]["aws_region"] but it was not found. Unrecoverable Error.')
                );
            }
		}
		
		protected function readBody(&$data) 
		{
			//retrieve body and encode it depending if its POST or GET
			if ($this->hasContent(@$data['requestContext']['http']['method'])) 
			{
				//accept only [POST | GET ] for now
				$this->method = $data['requestContext']['http']['method'];
				
				//POST Method
				if ($this->method == 'POST')
				{
					if ($this->hasContent($data['body']))
					{
						//check if content is Base64 Encoded
						if ((bool)preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $data['body'])) {
							$data['body'] = base64_decode($data['body']);
						}
                                
						$data = json_decode($data['body'], 1);
                    }                            
                }
                //GET Method
                else if ($this->method == 'GET')
				{
					if ($this->hasContent($data['rawQueryString'])) 
					{
						parse_str($data['rawQueryString'], $data);
					} else {
                        $data = [];
                    }
				} else {
                    //method is not POST | GET
                    return $this->paramerror = $this->doError(
                        sprintf('Method %s is not Allowed.', $this->method)
                    );
				}                    
			}			
		}
			
			
		protected function performParameterCheck($data)
		{
			if ($this->hasElements($this->metadata['paramsyntax']))
				$paramsyntax = $this->metadata['paramsyntax'];
			else
				$paramsyntax = [];
				
			if ($this->hasElements($data))
			{
				foreach ($data as $paramkey => $paramvalue) {
                    if (
                        //allowed parameters consist only a-z and 0-1 and -
                        preg_match('/^[a-z0-9\-]+$/', $paramkey) 
                        && 
                        //leading minus or trailing minus
                        strlen($paramkey[0]) == strlen(trim($paramkey[0], '-'))
                        &&
                        //not empty
                        !empty(strlen($paramkey))
                    )
                    {
						//if parameter is accepted or unexpected
                        if (isset($paramsyntax[$paramkey])) 
                        {
                            //param formatting and checks that dont halt the execution
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
                    	/*
                        	move parameter to ignored counter
                        	happens if:
                        	- parameter uses characters that are not allowed in parameter
                        	- parameter key starts with - for example 
                        */
                        $this->metadata['params']['ignored']++;
                        unset($data[$paramkey]);
                    }
				}
			}
			
            //find parameters that doesnt exist in $data
            if (is_array($paramsyntax))
            {
                foreach ($paramsyntax as $key => $prm) 
                {
                    if (isset($prm['default']) && !isset($data[$key])) {
                        $data[$key] = $prm['default'];
                    }
                    
                    if (isset($prm['required']) && !empty($prm['required'])) {
                        if (!isset($data[$key])) {
                            $this->paramerror = $this->doError(sprintf('required parameter "%s" was not found', $key));
                            return;                         
                        }
                    }
                }
            }			
			
			return $data;
		}

		
        //perform type test to enum.
        /*
            required parameter syntax key is:
                options => <array> of possible options that value must fall into
                
            allowed parameter syntax keys are:  
                mustexist => <boolean> - value must be one of the followings
                default => <string> - NB! fails in case set and not one of the options
        */
        
        private function doEnumParameterTypeTest(&$input, $parameter, $ps = []) 
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
        
        private function doArrayParameterTypeTest(&$input, $parameter, $ps = [])
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
        
        private function doBooleanParameterTypeTest(&$input, $parameter, $ps = [])
        {
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
            
            $input = sprintf('%b', $input);
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
        private function doIntegerParameterTypeTest(&$input, $parameter, $ps = []) {
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

        /* Result is returning JSON encoded successful response with statusCode 200 and Body */
        public function doOk($data = '', $pagination = []) 
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
        
        
        /* Result is returning JSON encoded error message with statusCode 400 and Body */
        public function doError($data = '') 
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
        public function doPlain($data)
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
            
        function paramDecrypt($data) {
            if (!isset($data)) return false;
            
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
        
        
        /* encrypting string with key and salt */
        protected function __doStringDecrypt($data) 
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
        
        
        
        /* encrypting string with key and salt */
        private function __doStringEncrypt($data) 
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
        

        function getConfig($path) {
            if (empty($path)) return '';
            $paths = explode('->', $path);
            
            $itens = $this->config;
            foreach($paths as $ndx) {
                if (isset($itens[$ndx])) $itens = $itens[$ndx];
            }
            return $this->paramDecrypt($itens);
        }            

		protected function getSalt() {
			return $this->salt;
		}
	}

?>