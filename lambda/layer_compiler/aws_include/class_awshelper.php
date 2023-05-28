<?php

    //type definitions for parser
    define('__TYPE_INITIALIZATION__', 'INITIALIZATION');
    define('__TYPE_AWS_FUNCTION__', 'AWS_FUNCTION');

    define('__AWS_API_REQUEST__', 'doAWSAPIRequest');
    define('__AWS_API_GET_LAMBDACLIENT__', 'getAWSLambdaClient');
    define('__AWS_API_GET_FUNCTION_BY_FUNCTION_NAME__', 'getFunctionDataByFunctionName');
    
    define('__MYSQL_ESTABLISH_CONNECTION__', 'doEstablishSQLConnection');
    define('__MYSQL_GET_CONNECTION__', 'getConnection');
    
    define('__MYSQL_FETCH_FIRST_ROW__', 'mysql_doQuerySingleRecord');
    define('__MYSQL_FETCH_FIRST_ROW_CELL__', 'mysql_getSingleCellValue');
    define('__MYSQL_FETCH_ALL_ROWS__', 'mysql_doQueryMultisetRecord');
    
    define('__MYSQL_RUN_QUERY__', 'mysql_doQuery');
    define('__MYSQL_RUN_GET_COUNT__', 'mysql_getCount');
    define('__MYSQL_RUN_INSERT_OR_UPDATE_ROW__', 'mysql_doInsertOrUpdate');
    
    define('__MYSQL_RUN_INSERT_QUERY__', 'mysql_doInsertQuery');
    define('__MYSQL_RUN_UPDATE_QUERY__', 'mysql_doUpdateQuery');
    
    define('__MYSQL_RUN_SILENT_QUERY__', 'mysql_doVoidQuery');
    define('__MYSQL_RUN_SOFT_DELETE__', 'mysql_doSoftDelete');
    
    define('__DECRYPT_STRING__', 'doStringDecrypt');
    define('__ENCRYPT_STRING__', 'doStringEncrypt');
    define('__CALCULATE_PAGINATION__', 'doPaginationCalculation');
    define('__NOONES_API_REQUEST__', 'doNoonesAPIRequest');
    define('__PAXFUL_API_REQUEST__', 'doPaxfulAPIRequest');
    define('__ERPLY_API_REQUEST__', 'doErplyAPIRequest');
    define('__PIGU_API_REQUEST__', 'doPiguAPIRequest');
    
    define('__GET_VAR__', '__getGlobalVariable');
    define('__SET_VAR__', '__setGlobalVariable');

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
                return $this->innererr('noting to decrypt');
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



        /* encrypting string with key and salt */
        private function __doStringEncrypt($data) 
        {
            if (empty($data['input'])) {
                return $this->innererr('noting to encrypt');
            }
            
            if (empty($this->salt)) {
                return $this->innererr('encryption SALT is not defined');
            }
            
            if (empty($this->config['encryption_key'])) {
                return $this->innererr('encryption key in config file is not set');
            }
            
            $encrypt_method = "AES-256-CBC";
            $key = hash('sha256', $this->config['encryption_key']);
            
            // iv - encrypt method AES-256-CBC expects 16 bytes - else you will get a warning
            $iv = substr(hash('sha256', $this->salt), 0, 16);
            
            $output = openssl_encrypt($data['input'], $encrypt_method, $key, 0, $iv);
            $output = base64_encode($output);
            
            return $this->innerok($output);
        }  


        
        /* function is calculating pagination information */
        private function __doPaginationCalculation($data) {
                if (!$data[$testfield = 'page']) return $this->innererr([
                    'message' => 'required parameter missing: [' . $testfield . ']'
                ]);                 
                
                if (!$data[$testfield = 'perpage']) return $this->innererr([
                    'message' => 'required parameter missing: [' . $testfield . ']'
                ]);                 
                
                if (!$data[$testfield = 'total-records']) return $this->innererr([
                    'message' => 'required parameter missing: [' . $testfield . ']'
                ]);                 
                
                $page = sprintf('%d', $data['page']);
                $perpage = sprintf('%d', $data['perpage']);
                $total_records = sprintf('%d', $data['total-records']);
                
                if ($page < 1) $page = 1;
                if ($perpage < 1) $perpage = 25;
                if ($perpage < 5) $perpage = 5;
                if ($perpage > 1000) $perpage = 1000;
                
                $total_pages = ceil($total_records / $perpage);
                //hard reset to lower stratum
                if ($page > $total_pages) $page = $total_pages;
                
                $offset = ($page - 1) * $perpage;
                $previous_page = $page - 1;
                $next_page = $page + 1;
                $adjacents = 2;
                
                if ($previous_page < 0)$previous_page = 0;
                
                return $this->innerok([
                    'result' => [
                        'page' => $page,
                        'perpage' => $perpage,
                        'offset' => $offset,
                        'previous_page' => $previous_page,
                        'next_page' => $next_page,
                        'adjacents' => $adjacents,
                        'total_pages' => $total_pages                       
                    ]
                ]);            
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
              CURLOPT_FOLLOWLOCATION => false,
              CURLOPT_POSTFIELDS => json_encode($data['payload']),
              CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
              CURLOPT_TIMEOUT => 0
            ]);                
            
            $result = curl_exec($ch);
            
            if ($result === false) {
                return $this->innererr(sprintf('CURL failed doAWSAPIRequest->%s() for endpoint %s', $data['endpoint'], $function_url));
            }

            if(curl_errno($ch))
            {
                return $this->innererr(sprintf('CURL failed doAWSAPIRequest->%s() for endpoint %s with error message: %s', $data['endpoint'], $function_url, curl_error($ch)));
            }
            
            curl_close($ch);
            $result = json_decode($result, 1);
            
            if ($result['status'] == 'error')
            {
                return $this->innererr(
                    [
                        'message' => 'UNABLE TO EXECUTE',
                        'endpoint' => $data['endpoint'] ?? 'N/A',
                        'function' => $data['lambda-function-name'] ?? 'N/A',
                        'url' => $function_url,
                        'payload' => $data['payload'],
                        'message' => $result['data']['message'],
                        'possible_causes' => [
                            'Parse Error in Destination Method',
                            'Payload Not Provided or Correctly Mapped'
                        ]
                    ]
                );

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
            
            
            
            return $this->innererr(
                [
                    'message' => 'NO DATA RETRIEVED',
                    'endpoint' => $data['endpoint'] ?? 'N/A',
                    'function' => $data['lambda-function-name'] ?? 'N/A',
                    'url' => $function_url,
                    'payload' => $data['payload'],
                    'possible_causes' => [
                        'Lambda URL is created but not accessible for outside world.',
                        'Parse Error in Method code that was uncatchable before execution',
                        'Result is not returned as $this->ok($message) | $this->err($message)',
                        'Payload type mismatch or invalid'
                    ]
                ]
            );
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
            
            $conn->set_charset("utf8");
            
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
        
        
        private function __doNoonesAPIRequest($data) 
        {            
            $api_key = $this->getConfig('api->noones->api_key');
            $api_secret = $this->getConfig('api->noones->api_secret');
            
            $payload = array(
                'apikey' => $api_key, 
                'nonce' => time()
            );            
            
            foreach($data['payload'] as $key => $val) $payload[$key] = $val;
            
            $apiseal = hash_hmac('sha256', http_build_query($payload, "", '&', PHP_QUERY_RFC3986), $api_secret);
            $payload['apiseal'] = $apiseal;
            
            $url = 'curl -X POST ' . 'https://noones.com/api/' . $data['request'] . ' -H "Accept: application/json" -H "Content-Type: text/plain" --data "' . http_build_query($payload, "", '&', PHP_QUERY_RFC3986) . '"';
            $data = json_decode(shell_exec($url), true);
            
            return $this->innerok($data);
        }


        private function __doPaxfulAPIRequest($data) 
        {            
            $api_key = $this->getConfig('api->paxful->api_key');
            $api_secret = $this->getConfig('api->paxful->api_secret');
            
            $payload = array(
                'apikey' => $api_key, 
                'nonce' => time()
            );            
            
            foreach($data['payload'] as $key => $val) $payload[$key] = $val;
            
            $apiseal = hash_hmac('sha256', http_build_query($payload, "", '&', PHP_QUERY_RFC3986), $api_secret);
            $payload['apiseal'] = $apiseal;
            
            $url = 'curl -X POST ' . 'https://paxful.com/api/' . $data['request'] . ' -H "Accept: application/json" -H "Content-Type: text/plain" --data "' . http_build_query($payload, "", '&', PHP_QUERY_RFC3986) . '"';
            $data = json_decode(shell_exec($url), true);
            
            return $this->innerok($data);
        }   
        
        
        
        private function __doPiguAPIRequest($data) {
            //payload:  149
            //  -endpoint (string)
            //  -payload (array)
            //  -method (POST,GET...) 
            //  -token (string);
            
            $basepath = 'https://pmpapi.pigugroup.eu/v2/' . $data['endpoint'];
            
            if ($data['endpoint'] == 'login') 
            {   
                $query = "
                SELECT 
                    kaup24_token as `token` FROM `pigu`.`pigu_token` 
                WHERE `active` = 1 AND DATE_ADD(date_created, INTERVAL 3 WEEK) > NOW()
                    ORDER BY date_created LIMIT 1";
                    
                $conn = $data['connection'];
                    
                if (!$res = $conn->query($query)) return $this->innererr($conn->error . ' in __doPiguAPIRequest placement 1');
                if (!mysqli_num_rows($res)) {
                    //have to create new token...
                    $conn->query("UPDATE `pigu`.`pigu_token` SET `active` = 0");
                    $data['payload'] = [
                        'username' => $this->getConfig('api->pigu->username'),
                        'password' => $this->getConfig('api->pigu->password')
                    ];
                    $data['token'] = 'undefined';
                    
                } else {
                    $row = mysqli_fetch_assoc($res);
                    return $this->innerok($row['token']);
                }
            }
            
            $postop = [];
            if ($data['method'] == 'GET')
            {
                if (is_array($data['payload']))
                    $basepath .= '?' . http_build_query($data['payload']);
            } else
            if ($data['method'] == 'PATCH' or $data['method'] == 'POST')
            {
                $postop = $data['payload'];
            }                
            else
                return $this->innererr('no such HTTP method: "' . $data['method'] . '"');                

            $curl = curl_init();
            
            $params = [
                CURLOPT_URL => $basepath,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json', 
                    'Accept: application/json',
                    'Authorization: Pigu-mp ' . $data['token']
                ]
            ];
            
            if ($data['method'] != 'GET') {
                $params[CURLOPT_CUSTOMREQUEST] = $request;
                $params[CURLOPT_POSTFIELDS] = json_encode($postop);
            }
            
            curl_setopt_array($curl, $params);       
            
            $response = curl_exec($curl);
            if(curl_errno($curl) or empty($response)) {
                sleep(3); // Oota 3 sekundit
                $response = curl_exec($curl); // Proovi uuesti
            }
            
            curl_close($curl);
            
            $return = json_decode($response); 
            if (json_last_error() === JSON_ERROR_NONE) {
                $result = json_decode($response, 1);
            } else {
                return $this->innererr('something happened here.... should not happen!');
            }
            
            
            if ($data['endpoint'] == 'login') {
                $newtoken = $result['token'];
                $conn->query(sprintf("INSERT INTO `pigu`.`pigu_token` SET `kaup24_token` = '%s', `date_created` = NOW(), `active` = 1",
                    $newtoken));
                return $this->innerok($result['token']);
            }
            
            return $this->innerok($result);
            
        }
        
        
        
        private function __doErplyAPIRequest($data)
        {
            //payload:  141
            //  -request (string)
            //  -payload (array)
            //  -method (POST,GET...)
            
            $clientid = $this->getConfig('api->erply->clientid');
            $erplypass = $this->getConfig('api->erply->password');

            $conn = $data['connection'];
            
            $query = "SELECT session_key FROM `erply`.`erply_session_key` WHERE TIMESTAMPDIFF(MINUTE, `session_time`, NOW()) < 45 AND session_id = 1";
                
            if (!$res = $conn->query($query)) return $this->innererr($conn->error . ' in __doErplyAPIRequest placement 1');
            $row = mysqli_fetch_assoc($res);
            $sk = $row['session_key'];                
            
            if (!$sk or $sk == '')
            {
                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL => 'https://' . $clientid . '.erply.com/api/?clientCode=' . $clientid . '&request=verifyUser&username=api&password=' . $erplypass,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                ));
                
                $sk = json_decode(curl_exec($curl), 1)['records'][0]['sessionKey'];
                $query = "UPDATE `erply`.`erply_session_key` SET `session_key` = '" . $sk . "', session_time = NOW() WHERE session_id = 1";
                if (!$res = $conn->query($query)) return $this->innererr($conn->error . ' in __doErplyAPIRequest placement 2');
                curl_close($curl);
            }
            
            if (!$sk) return $this->innererr('session_key was not defined');
            
            //make rest of the query
            $data['payload']['sessionKey'] = $sk;
            $data['payload']['clientCode'] = $clientid;
            $data['payload']['request'] = $data['request'];         
            
            $query = http_build_query($data['payload']);
            
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://' . $clientid . '.erply.com/api/?' . $query,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => $data['method'],
                CURLOPT_HTTPHEADER => ['accept: application/json'],
            ));            
            
            $response = curl_exec($curl);
            return $this->innerok(json_decode($response, 1));
        }
        
        private function __getGlobalVariable($data) 
        {
            //payload:   139
            //  -connection
            //  -key
            //  -default
            if (!is_object($data['connection'])) return $this->innererr('Required MySQL connection __getGlobalVariable() for is not established: ' . print_r($data));
            $conn = $data['connection'];
            
            $query = "SELECT IFNULL(variable_value, '" . addslashes($data['default']) . "') as `result` FROM `aws_lambda_core`.`_aws_global_variables` WHERE variable_key = '" . addslashes($data['key']) . "'";
            if (!$res = $conn->query($query)) {
                return $this->innererr($conn->error);
            }
            if (!mysqli_num_rows($res)) return $this->innerok($data['default']);
            
            $row = mysqli_fetch_assoc($res);
            
            return $this->innerok($row['result']);
        }
        
        
        private function __setGlobalVariable($data) {
            //payload:   140
            //  -connection
            //  -key
            //  -value
            
            if (!is_object($data['connection'])) return $this->innererr('Required MySQL connection __setGlobalVariable() for is not established: ' . print_r($data));
            $conn = $data['connection'];
            
        }
        
    }

?>
