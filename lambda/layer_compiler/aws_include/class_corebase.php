<?php

    class corebase 
    {
        //declare protected inheritable variables
        private $returned = 0;
        private $lifetime_executions = 0;
        private $systemparams = [];
        
        private $configfile = '/opt/config/configuration.json';
        private $config_dir;
        private $function_parameters_dir;
        private $system_parameters_dir;        

        protected $config = [];
        
        //public variables
        public $task = [];
        public $aws_region;
        public $metadata = [];
        public $version = '1.1.0';
        public $last_error;
    
        //will be launched when we start a new server infinite loop
        public function initialization($function) 
        {
            $this->lifetime_executions = 0;
            
            $this->config_dir = '/opt/config/';
            $this->function_parameters_dir = $this->config_dir . 'params/';
            $this->system_parameters_dir = $this->function_parameters_dir . 'system/';
        
            //constructing basic class metadata
            $this->constructMetadata($function);            

            //read in global configuration
            $this->readConfig(); 
            
            $this->metadata['paramsyntax'] = $this->loadFunctionParameters($function);
            
            //load system function parameters
            $this->loadSystemFunctionParameters();
            
            //anything we return here will be displayed as an error in bootstrap
            //for example: return "error occured"
        }
        
        //PUBLIC SCOPE METHOD DECLARATIONS    
        
        //will be started prior every run
        public function prepare(&$data, $customparams = NULL) 
        {
            //reset the return counter
            //$this->metadata['uku'] = $this->metadata['paramsyntax'];
            
            $this->returned = 0;
            
            // read request headers and see what we get from there
            if ($err = $this->readHeaders($data))
                return $err;

            //read request body
            if ($err = $this->readBody($data)) {
                return $err;
            }
            
            //clean and validate parameters
            $response = NULL;
            if ($response = $this->performParameterCheck($data, is_null($customparams) ? NULL : $customparams)) {
                return $response . ' on payload: ' . json_encode($data);
            }
            //anything we return here will be displayed as an error in bootstrap
            //for example: return "error occured"
        }        
        
        public function getSystemFunctionParameters($function_name) {
            if (isset($this->systemparams[$function_name]))
                return $this->systemparams[$function_name];
        }
        
        
        //if user has ever requested function $this->ok or $this->err in function
        public function resultreturned() {
            return $this->returned;
        }
        
        /* by entering input parameters we return if element is array and can be forlooped */
        public function hasElements($variable = NULL) {
            return (isset($variable) && is_array($variable) && count($variable));
        }
        
        //making sure the variable has a length and its defined
        public function hasContent($variable = NULL) {
            return (isset($variable) && !empty($variable));
        }
        
        public function seterr($error) {
            $this->last_error = $this->err($error);
            $this->returned++;
            return false;
        }

        //generating general public error message json with payload
        public function err($data = NULL, $extrafields = [])
        {
            /*if (debug_backtrace()[1]['function'] == 'run')*/ 
            {
                $this->returned++;
                $this->lifetime_executions++;
            }
            $this->timerstop();
            $this->returnStatus = 400;

            //error - 400
            if (is_string($data)) $data = trim($data);
            if (empty($data)) {
                //return as a boolean
                $data = ['code' => $this->returnStatus, 'error' => 1];
            } else
            if (is_object($data)) {
                $data = ['code' => $this->returnStatus, 'message' => $data, 'methods' => get_class_methods($data)];
            } else                
            if (!is_array($data)) 
            {
                //result as a string message
                $data = ['code' => $this->returnStatus, 'message' => $data];
            } else
            {
                //result as an array
                $data = ['code' => $this->returnStatus, 'message' => $data];
            }
            
            $json = json_encode(
            [
                'status' => "error",
                'data' => $data,
                'timestamp' => time(),
                '@metadata' => $this->getMetadata()
            ], JSON_FORCE_OBJECT);
                    
            $result = json_encode([
                'statusCode' => $this->returnStatus,
                'body' => $json,
            ]);
            
            return $result; 
        }

        //generating general public success message json with payload
        public function ok($data = NULL, $pagination = [])
        {
            /*if (debug_backtrace()[1]['function'] == 'run') */ {
                $this->returned++;
                $this->lifetime_executions++;
            }
            $this->timerstop();
            $this->returnStatus = 200;
            //success - 200
            if (is_string($data)) $data = trim($data);
            if (empty($data)) {
                //return as a boolean
                $data = ['code' => $this->returnStatus, 'success' => sprintf('%b', $data)];
            } else
            if (is_object($data)) {
                $data = ['code' => $this->returnStatus, 'result' => $data];
            } else                
            if (!is_array($data)) 
            {
                //result as a string message
                $data = ['code' => $this->returnStatus, 'result' => $data];
            } else
            {
                //result as an array
                $return = $data;
                if ($this->hasElements(@$pagination))
                {
                    foreach ($pagination as $k => $v) {
                        if (in_array($k, ['page', 'perpage', 'offset', 'previous_page', 'next_page', 'adjacents', 'total_pages']))
                            $return['pagination'][$k] = $v;
                    }
                }
                
                $data = ['code' => $this->returnStatus, 'records' => $return];
            }
            
            $json = json_encode(
            [
                'status' => "success",
                'data' => $data,
                'timestamp' => time(),
                '@metadata' => $this->getMetadata(),
            ], JSON_FORCE_OBJECT);
                    
            $result = json_encode([
                'statusCode' => $this->returnStatus,
                'body' => $json,
            ]);
            
            return $result;
        }   
        

        //PRIVATE FUNCTION DECLARATIONS
        /* we load default paramsyntax for the requester function */
        private function loadFunctionParameters($function_name) 
        {
            if (file_exists($syntaxfile = $this->function_parameters_dir . $function_name . '.json')) {
                $json = file_get_contents($syntaxfile);
                try {
                    $paramsyntax = json_decode($json, 1);
                } catch (Exception $e) {
                    $paramsyntax = [];
                }
            }
            return $paramsyntax;
        }
        
        
        private function loadSystemFunctionParameters()
        {
            if (file_exists($this->system_parameters_dir)) 
            {
                $contents = scandir($this->system_parameters_dir);
                foreach ($contents as $void => $content) 
                {
                    if ($match = preg_match('/^__[_a-zA-Z0-9]+.json$/', $content)) 
                    {
                        //$funcname = trim($content, '.json');
                        //if (empty($funcname)) continue;
                        
                        $json = file_get_contents($this->system_parameters_dir . $content);
                        if (empty($json)) continue;
                        $this->systemparams[str_replace('.json', '', $content)] = json_decode($json, 1);
                    } 
                }
                if (!$this->systemparams) $this->systemparams = [];
            } else $this->systemparams = [];
        }
        
        
        //stopping the execution timer to calculate total execution time.
        //lambda lag will be added to your lambda billing
        private function timerstop()
        {
            if (empty($this->metadata['timer']['started_at_microtime'])) return;
            else 
                if ($this->metadata['timer']['started_at_microtime'] == 'N/A') return;

            $this->metadata['timer']['ended_at_microtime'] = intval(explode(' ', microtime())[1] * 1E3) + intval(round(explode(' ', microtime())[0] * 1E3));
            //calculate execution time
            $this->metadata['timer']['execution_time'] = $this->metadata['timer']['ended_at_microtime'] - $this->metadata['timer']['started_at_microtime'];                        
        }
        

        /* function is preparing metadata for displaying and registering its base structure */
        private function constructMetadata($function) 
        {
            //construct base metadata structure
            $this->metadata = [
                'caller' => [
                    'class' => $function
                ],
                'timer' => [],
                'paramsyntax' => [],
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
        
        
        private function getMetadata() {
            $this->metadata['returned'] = $this->returned;
            $this->metadata['lifetime_cycles'] = $this->lifetime_executions;
            return $this->metadata;
        }
        
        /* reading config from global config file */
        private function readConfig() 
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
            
            if (empty($this->config['environment'])) return 'working environment not provided in config';
            $this->metadata['caller']['environment'] = $this->config['environment'];
        }
        
        /* reading headers from AWS Lambda Request*/
        private function readHeaders($data) 
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
                } else return ('aws_region not provided in config $this->config["aws"]["aws_region"]');
            }
        }
        
        private function readBody(&$data) 
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
                    return sprintf('Method %s is not Allowed.', $this->method);
                }                    
            }           
        }
        
        
        public function performSystemParameterCheck(&$input, $rules) {
            return $this->performParameterCheck($input, $rules, true);
        }
            
            
        private function performParameterCheck(&$data, $customparams = NULL, $dry_run = NULL)
        {
            $prm = [];
            $this->paramerror = '';
            if ($this->hasElements(@$customparams)) {
                $paramsyntax = $customparams;
            } else {
                $paramsyntax = $this->metadata['paramsyntax'];
            }
            
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
                            if (empty($dry_run)) {
                                $this->metadata['params']['accepted'][$paramkey] = 1;
                            }
                            
                            $ps = $paramsyntax[$paramkey];
                            
                            //param formatting and checks that dont halt the execution
                            $allowed = ['integer', 'boolean', 'string', 'array', 'enum'];
                            if (!in_array($ps['type'], $allowed))
                            {
                                return sprintf('Required parameter syntax "type" not found for parameter "%s" or is not [ ' . implode(' | ', $allowed) . ' ]', $paramkey);
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
                            
                            if (!empty($this->paramerror)) return $this->paramerror;
                            
                        } else {
                            //move parameter to unexpected parameters list
                            if (empty($dry_run)) 
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
                        if (empty($dry_run)) 
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
                            return sprintf('required parameter "%s" was not found', $key);
                        }
                    }
                }
            }
            
            return false;
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
                $this->paramerror = (sprintf('required parameter "%s" is expecting enum "options" but not found', $parameter));
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
                    $this->paramerror = (sprintf('required parameter "%s" default enum [ %s ] is not one of the following [ %s ]', $parameter, $ps['default'], implode(' | ', $ps['options'])));
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
                    $this->paramerror = (sprintf('required parameter "%s" [ %s ] is not one of the following [ %s ]', $parameter, $input, implode(' | ', $ps['options'])));
                    return;
                }
                
                if (empty($ps['default'])) {
                    $this->paramerror = (sprintf('required parameter "%s" has no default from enum options [ %s ]', $parameter, implode(' | ', $ps['options'])));
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
                    if (!empty($ps['required'])) {
                        $this->paramerror = (sprintf('array $data[%s] is expected but not found (required = ' . $ps['required'] . ')', $parameter, $key));
                        return;
                    }
                }
                if (!is_array($input) || empty($input)) 
                {
                    if (!empty($ps['required'])) 
                    {
                        $this->paramerror = (sprintf('array $data[%s] is expected but not found (required = ' . $ps['required'] . ')', $parameter, $key));
                        return;
                    }
                    $input = [];
                }
            }
            
            if (isset($ps['min-count']) && (count($input) < $ps['min-count'])) {
                $this->paramerror = (sprintf('required parameter "%s" is expecting %d elements but %d found', $parameter, $ps['min-count'], count($input)));
                return;
            }
            
            if (!is_array($input)) {
                $this->paramerror = (sprintf('required parameter "%s" is expecting to be array type but its not. Use valid "default" JSON', $parameter));
                return;
            }
            
            if (!empty($ps['require-keys'])) {
                $requiredkeys = explode(',', $ps['require-keys']);
                foreach ($requiredkeys as $key) {
                    $key = trim($key);
                    if (!isset($input[$key])) {
                        $this->paramerror = (sprintf('array key $%s[%s] was expected but not found', $parameter, $key));
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
            if ($input == '' && $ps['empty-is-error'] == 1) {
                $this->paramerror = (sprintf('required parameter "%s" is not one of the following [ true | false | 1 | 0 ]', $parameter));
            }
            
            if ($input == '' && isset($ps['default'])) {
                $input = sprintf('%b', $ps['default']);
            }
            
            if ((!$input) && isset($ps['fail-if-false'])) {
                $this->paramerror = (sprintf('required parameter "%s" is false and "fail-if-false" flag is set', $parameter));
            }
            
            if (($input) && isset($ps['fail-if-true'])) {
                $this->paramerror = (sprintf('required parameter "%s" is true and "fail-if-true" flag is set', $parameter));
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
                $this->paramerror = (sprintf('required parameter "%s" is empty', $parameter));
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
                $this->paramerror = (sprintf('required parameter "%s" is empty', $parameter));
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
            $originput = $input;
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
                    $this->paramerror = (sprintf('required parameter "%s" is not length of %d but %d instead', $parameter, $ps['length'], strlen($input)));
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
                $this->paramerror = (sprintf('required parameter "%s" is empty (original input: "' . $originput . '")', $parameter));
            }
        }       

        function paramDecrypt($data) {
            if (!isset($data)) return 1;
            
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
        
        
        private function strDecrypt($data) {
            $output = 'result';
            if ($err = $this->execute(${$output}, ['command' => 'doStringDecrypt', 'parameters' => ['input' => $data]])) { $this->paramerror = ($err); return; }
            return $result;
        }
        
        
        private function strEncrypt($data) {
            $output = 'result';
            if ($err = $this->execute(${$output}, ['command' => 'doStringEncrypt', 'parameters' => ['input' => $data]])) { $this->paramerror = ($err); return; }
            return $result;
        }
        
        
        public function parseJSONfromFile($file)
        {
            if (!file_exists($file)) return [];
            $json = file_get_contents($file);
            if ($json == '') return [];

            try {
                $jsonarr = json_decode($json, $associative=true, $depth=512, JSON_THROW_ON_ERROR);
            } catch (Exception $e) {
                return [];
            }
            if (isset($jsonarr) && is_array($jsonarr)) return $jsonarr;
            return [];
        }
        

        public function getConfig($path) {
            if (empty($path)) return '';
            $paths = explode('->', $path);
            
            $itens = $this->config;
            foreach($paths as $ndx) {
                if (isset($itens[$ndx])) $itens = $itens[$ndx];
                else return 'NO-SUCH-INDEX';
            }
            return $this->paramDecrypt($itens);
        }            

        private function getSalt() {
            return $this->salt;
        }
    }

?>
