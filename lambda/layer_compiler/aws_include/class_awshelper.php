<?php

    define('__TYPE_INITIALIZATION__', 'INITIALIZATION');
    define('__TYPE_AWS_FUNCTION__', 'AWS_FUNCTION');

    define('__L4H_GET_FUNCTION_BY_FUNCTION_NAME__', 'getFunctionDataByFunctionName');
    define('__MYSQL_GET_SINGLE_RECORD__', 'mysql_doQuerySingleRecord');
    define('__MYSQL_GET_SINGLE_RECORD_CELL__', 'mysql_getSingleCellValue');
    define('__MYSQL_GET_MULTISET_RECORD__', 'mysql_doQueryMultisetRecord');
    
    define('__MYSQL_ESTABLISH_CONNECTION__', 'doEstablishSQLConnection');
    define('__MYSQL_GET_CONNECTION__', 'getConnection');
    define('__MYSQL_GET_QUERYSET__', 'mysql_doQuery');
    define('__MYSQL_GET_COUNT__', 'mysql_getCount');
    
    
    
    define('__MYSQL_RUN_INSERT_OR_UPDATE_ROW__', 'mysql_doInsertOrUpdate');
    define('__MYSQL_RUN_INSERT_QUERY__', '__mysql_doInsertQuery');
    define('__MYSQL_RUN_SILENT_QUERY__', '__mysql_doVoidQuery');
    
    define('__MYSQL_SOFT_DELETE__', 'mysql_doSoftDelete');

    class awshelper extends corebase
    {   
        //private key
        private $salt = "TjZpB8609WkpKG5ftdvQ";
        private $functionparams = [];
        
        public $metadata; //execution related metadata
        public $method; // POST | GET
        public $paramerror;
        
        public $version;
        
        /*
            MOTHER OF ALL PRIVATE FUNCTIONS - execute()
            
            wrapper to call all this class private functions with ['command' => 'funcname'()]
            
            returns result in case of error only.
            if no error, $successresult will return the message from the function
            
            $output = 'quantity';
            if ($err = $this->executer(${$output}, [
                'command' => 'mysql_getCount',
                'parameters' => [
                    'connection' => 'core',
                    'query' => $query
                ]
            ])) return $this->innererror($err);            
        */  

        function execute(&$successresult, $data) 
        {
            $data['command'] = trim($data['command'], '_');

            //check for command
            if (empty($data['command'])) 
                return 'Function command was not provided in a Execute Call from: ' . debug_backtrace()[0]['function'];
                
            if (!is_string($data['command'])) {
                return 'Function command has to be String in format of ( _a-zA-Z )';
            }
            
            //construct function name
            $command = sprintf('__%s', $data['command']);
            
            //make parameter test for $command
            $sysparams = $this->getSystemFunctionParameters($command);
            if (empty($this->hasElements($sysparams))) {
                return 'Unable to locate function parameters in system functions folder for function: ' . $command . '()';
            } else {
                $restresult = $this->performSystemParameterCheck($data['parameters'], $sysparams);
                if (!empty($restresult)) {
                    return $restresult;
                }
            }
            //return  print_r($sysparams, 1);

            //construct database connection if param 'connection' is provided
            //this is always our mysql trigger
            if ($this->hasContent(@$data['parameters']['connection']))
            {
                //connection not yet created
                if (!isset($this->metadata['connections'][$data['parameters']['connection']]['object']) && $data['command'] != __MYSQL_ESTABLISH_CONNECTION__
                ) {
                    if ($err = $this->execute(${$output = 'conn'}, [
                        'command' => __MYSQL_ESTABLISH_CONNECTION__, 
                        'parameters' => [
                            'connection' => $data['parameters']['connection']
                        ]
                    ])) { return $err; }
                }
            }

            //check if command is in format of someclass_runImportantMethod
            $potential_class = $this->parseExternalModuleClass($data['command']);
            if (is_string($potential_class)) 
                return 'Command Incorrect: ' . $data['command'] . '()';
                
            $external = $internal = false;
            if (is_array($potential_class) && count($potential_class) && ($potential_class['class'])) 
            {
                //need to run through $module->method
                if ($ext_error = $this->prepareModule($potential_class, $module)) {
                    return $ext_error;
                } else $external = true;
            } else {
                //need to run via $this->method
                if (!$internal_error = $this->prepareSelf($data['command'])) {
                    return $internal_error;
                } else $internal = true;
            }
            
            $class_used = '';
            
            try {
                if ($external) {
                    if (isset($data['parameters']['connection'])) {
                        //replace connection slug with connection object
                        $data['parameters']['connection'] = $this->metadata['connections'][$data['parameters']['connection']]['object'];
                    }
                    $result = call_user_func_array(array($module, $command), array($data['parameters'] ?? []));
                    $class_used = get_class($module);
                }
                else if ($internal) {
                    if (isset($data['parameters']['connection'])) 
                        if ($data['command'] != __MYSQL_ESTABLISH_CONNECTION__)
                            if ($data['command'] != __MYSQL_GET_CONNECTION__)
                    {
                        //replace connection slug with connection object
                        $data['parameters']['connection'] = $this->metadata['connections'][$data['parameters']['connection']]['object'];
                    }                    
                    $result = call_user_func_array(array($this, $command), array($data['parameters'] ?? []));
                    $class_used = 'this';
                }
            }
            catch(Exception $e) { return $e->getMessage(); }
            catch(Throwable $t) { return $t->getMessage(); }
            
            if (is_object($result)) {
                $successresult = $result;
                return false;
            }
            
            //detect what kind of result was sent            
            if (isset($result['inner'])) {
                if (isset($result['success'])) {
                    $successresult = $result['message'];    
                    return false;
                } else 

                if (!empty($result['error']))
                {
                    return $result['message'];
                } else { 
                    return sprintf('$%s->%s() was expecting [ return innerok($success_confirmation) || return innererr($error) ] but empty return found: ' . print_r($result, 1),
                        $class_used, $command); 
                }
            } else { 
                return sprintf('$%s->%s() was expecting [ return innerok($success_confirmation) || return innererr($error) ] but empty return found: ' . print_r($result, 1), 
                    $class_used, $command);
            }
        }
        
        private function prepareSelf($command) {
            if (!method_exists($this, $command)) {
                return (sprintf('Unable to find Module method $%s->%s()', get_class($this), $command));
            }
            
            if (!is_callable($this, $command)) {
                return (sprintf('Module method $%s->%s() is not Callable', $module, $params['method']));
            }            
            
            return false;
        }        
        
        //attempt to prepare external module and validate its integrity and test against parse error
        private function prepareModule($params, &$resultclass) 
        {
            if (!file_exists($includefile = $params['include'])) {
                $err = sprintf('Couldnt Include File %s');
            }
            
            $module = $params['class'];
            
            if (empty($module)) return ('Invalid Module Name');
            
            //if module is already loaded dont repeat the creating
            if (is_object(@$this->metadata['modules'][$module])) {
                $resultclass = $this->metadata['modules'][$module];
                //return false;
            }            
            
            try {
                require_once($includefile);
                //class not found
                if (!class_exists($module)) {
                    return (sprintf('class %s was requested but not found in file %s', $module, $includefile));
                }
                //create class and assign
                $this->metadata['modules'][$module] = new $module;
            }
            catch(Throwable $t) {
                return ('Parse Error occured when including class file: ' . $includefile);
            }
            
            if (!is_object($this->metadata['modules'][$module])) {
                return (sprintf("Couldnt initialize $module and its method %s", $params['method']));
            }            
            
            if (!method_exists($this->metadata['modules'][$module], $params['method'])) {
                return (sprintf('Unable to find Module method $%s->%s()', $module, $params['method']));
            }
            
            $resultclass = $this->metadata['modules'][$module];
            return false;
        }        
        
        
        //for sending internal success message from $this->executer()
        private function innerok($res) 
        {
            return [
                'inner' => 1,
                'success' => 1,
                'message' => $res
            ];
        }
        
        //for sending internal error message from $this->executer()
        private function innererr($res) 
        {
            return [
                'inner' => 1,
                'error' => 1,
                'message' => $res
            ];
        }                 
        
        /* attempting to get class preparation data from $command name */
        private function parseExternalModuleClass($command) 
        {
            $command = trim($command, '_');
            if (empty($command)) return false;
            
            $command = explode('_', $command);
            
            if (!is_array($command) && (count($command) != 2)) return false;
            
            if (empty($command[0]) or empty($command[1])) return false;
            if (!preg_match("/^[a-z]+$/", $command[0])) return false;
            if (!preg_match("/^[a-zA-Z]+$/", $command[1])) return false;
            
            $class_filename = sprintf('class_%s.php', $command[0]);
            
            if (!file_exists($includefile = $this->config['sourcedir'] . $class_filename))
            if (!file_exists($includefile = $this->config['workdir'] . $class_filename))
            if (!file_exists($includefile = $this->config['includedir'] . $class_filename)) { return 'Couldnt Include Matching File for Requested Class: ' . $includefile . print_r(scandir('/opt/includes/'), 1); }
            
            return [
                'class' => $command[0],
                'method' => '__' . $command[0] . '_' . $command[1],
                'filename' => $class_filename,
                'include' => $includefile
            ];            
        }        
        
        //WE START WITH FUNCTION THAT ARE ACCESSIBLE VIA $this->execute() METHOD
        
        
        
        
        
        
        
        /*
            function returns mysql object if asked from doExecute
            $output = 'conn';
            if ($err = $helper->doExecute(${$output}, [
                'command' => 'getConnection',
                'parameters' => [
                    'connection' => 'core'
                ]
            ])) return $helper->err($err);
            
            return $helper->doOk(print_r($conn, 1));       
            
        */
        
        private function __getConnection($data) 
        {
            $conn_slug = $data['connection'];
            if (!is_string($conn_slug)) return $this->innererr('Connection slug is not set or not string');
            
            if (empty($connpool = $this->metadata['connections']))
                return $this->innererr(sprintf('Unable to Access Connection Pool: $helper->metadata["connections"]'));
                
            if (empty($connection = $connpool[$conn_slug]))
                return $this->innererr(sprintf('Unable to Access Connection Pool Connection: $helper->metadata["connections"]["%s"]', $conn_slug));
                
            if (empty($connection['established']))
                return $this->innererr(sprintf('Connection Pool Connection is not Established: $helper->metadata["connections"]["%s"]', $conn_slug));
            
            if (!is_object($connection['object']))
                return $this->innererr(sprintf('Connection is Promesed to be Established but No SQL Object: $helper->metadata["connections"]["%s"]', $conn_slug));
                
            return $connection['object'];
        }
        
        
        /* encrypting string with key and salt */
        private function __doStringDecrypt($data) 
        {
            if (empty($data['input'])) {
                return $helper->innererr('noting to decrypt');
            }
            
            if (empty($this->salt)) {
                return $this->innererr('decryption SALT is not defined');
            }
            
            if (empty($this->config['encryption_key'])) {
                return $this->innererr('decryption key in config file is not set');
            }
            
            $encrypt_method = "AES-256-CBC";
            $key = hash('sha256', $this->config['encryption_key']);
            
            // iv - encrypt method AES-256-CBC expects 16 bytes - else you will get a warning
            $iv = substr(hash('sha256', $this->salt), 0, 16);
            $output = openssl_decrypt(base64_decode($data['input']), $encrypt_method, $key, 0, $iv);
            
            return $this->innerok($output);
        }        
        
        /* function is creating AWS Lambda Client for the user */
        private function __getAWSLambdaClient($data) 
        {
            $lambdaclient = new \Aws\Lambda\LambdaClient([
                'region' => $data['region'],
                'version' => '2015-03-31',
                'credentials' => [
                    'key' => $this->getConfig('aws->aws_key'),
                    'secret' => $this->getConfig('aws->aws_secret')
                ]
            ]);
            return $lambdaclient;
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
                ])) return $helper->err($err);                 
        */
        private function __doAWSAPIRequest($data) 
        {
            //we replace this later
            if (empty($data['lambda-function-name']) && empty($data['lambda-function-url'])) {
                return $this->innererr('one of the parameters is missing: [ lambda-function-name | lambda-function-url  ]');
            }
            
            if (empty($data['lambda-function-url'])) 
            {
                //lambda-function-name path was sent in
                if ($err = $this->execute(${$output = 'function_url'}, [
                    'command' => 'mysql_getSingleCellValue',
                    'parameters' => [
                        'tablename' => '_aws_' . $data['region'] . '_functions',
                        'where' => [
                            'function_name' => $data['lambda-function-name'],
                            'region' => $data['region']
                        ],
                        'column' => 'function_url',
                        'singleexpected' => 1,
                        'connection' => 'core'
                    ]
                ])) { 
                    if ($err == 'NULL') return $this->innererr(sprintf('Unable to retrieve Amazon URL for Function %s', $data['lambda-function-name']));
                    else return $this->innererr($err); 
                }
                
                if (!$function_url || $err) {
                    return $this->innererr(sprintf('Error 404: API lambda-function-name %s not found', $data['lambda-function-name']));
                }                
            } else {
                //lambda-function-url was sent in
                $function_url = $data['lambda-function-url'];
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
                return $this->innererr(sprintf('CURL failed doAWSAPIRequest->%s() for endpoint %s', $data['endpoint'], $function_url));
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
                
                return $this->innererr(sprintf($message, $this->metadata['caller']['function'], $result['data']['message'], $function_url, $data['endpoint'], print_r($data['payload'], 1)));
            }
            else
            if ($result['status'] == 'success')
            {
                if (isset($result['data']['result']))
                    return $this->innerok($result['data']['result']);
                else if (isset($result['data']['records']))
                    return $this->innerok($result['data']['records']);
                else return $this->innerok($result['data']);
            }
            
            return $this->innererr(sprintf('NO DATA retrieved from doAWSAPIRequest->%s() @ URL %s. Parse Error in destination file or URL incorrect?', $data['endpoint'], $function_url));
        }

        /*
            function is establishing required connection based on the connection slug and storing it to
            $this->metadata['connection'][<connection_slug>]['object']

            for connection to be created you need following config:

            'connections' => [
                <connection_slug> => [
                    'hostname' => <hostname>,
                    'username' => <username>,
                    'password' => <password>,
                    'database' => <database>
                ]
            ]

            connection can be retrieved:

            $this->helper();
        */
        private function __doEstablishSQLConnection($data) 
        {
            if (!$data['connection']) {
                return $this->innererr('no connection parameter defined');
            }
            
            if (!is_string($data['connection'])) {
                return $this->innererr('connection parameter is not defined as string');
            }            
            
            if (isset($this->metadata['connections'][$data['connection']]['object'])) {
                return $this->innerok(sprintf('connection was already established and can be found @ $helper->conn[%s]', $data['connection']));
            }                
            
            if (!$this->config['connections'][$data['connection']]) {
                return $this->innererr(sprintf('config doesnt provide $config[connections][%s]', $data['connection']));
            }
            
            if (empty($this->config['connections'][$data['connection']]['hostname'])) {
                return $this->innererr(sprintf('config doesnt provide $config[connections][%s][hostname]', $data['connection']));
            }
            
            if (empty($this->config['connections'][$data['connection']]['username'])) {
                return $this->innererr(sprintf('config doesnt provide $config[connections][%s][username]', $data['connection']));
            }
            
            if (empty($this->config['connections'][$data['connection']]['password'])) {
                return $this->innererr(sprintf('config doesnt provide $config[connections][%s][username]', $data['password']));
            }
            
            $cs = [
                'hostname' => $this->paramDecrypt($this->config['connections'][$data['connection']]['hostname']),
                'username' => $this->paramDecrypt($this->config['connections'][$data['connection']]['username']),
                'password' => $this->paramDecrypt($this->config['connections'][$data['connection']]['password']),
                'database' => $this->paramDecrypt($this->config['connections'][$data['connection']]['database'])
            ];
            
            mysqli_report(MYSQLI_REPORT_ALL ^ MYSQLI_REPORT_INDEX);
            
            if (!$conn = mysqli_connect(
                $cs['hostname'], 
                $cs['username'], 
                $cs['password'], 
                $cs['database']
            )) {
                return $this->innererr(sprintf('unable to open MySQL connection [%s] %s', $data['connection'], print_r($cs, 1)));
            }
            
            if (!is_object($conn)) {
                return $this->innererr('$conn from establisConnection is not mysql class');
            }
            
            $this->metadata['connections'][$data['connection']] = [
                'established' => true,
                'object' => $conn
            ];
            
            $this->conn[$data['connection']] = $conn;
            
            return $this->innerok(sprintf('connection established and can be found @ $helper->conn[%s]', $data['connection']));
        }
        
        
        //lambda4humans specific commands
        private function __getFunctionDataByFunctionName($data) 
        {
            if (!is_object($data['connection'])) return $this->innererr('Required MySQL connection __getFunctionDataByFunctionName() for is not established: ' . print_r($data));
            $conn = $data['connection'];
            
            if (!$res = $conn->query(sprintf("SELECT * FROM `_aws_" . $this->aws_region . "_functions` WHERE function_name = '%s'", $data['function-name']))) {
                return $this->innererr($conn->error);
            }

            if (!mysqli_num_rows($res)) return $this->innererr(sprintf('Requested Function Not Found: %s', $data['function-name']));
            $row = mysqli_fetch_assoc($res);
            
            return $this->innerok($row);
            
            
        }
        
    }

?>
