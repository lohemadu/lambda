<?php

    /* Bootstrap For Parameter Check */
    //initialize core
    if (!class_exists("corebase")) {
        if (file_exists($cb = '/var/task/src/class_corebase.php')) require_once($cb);
        else
            if (file_exists($cb = '/opt/includes/class_corebase.php')) require_once($cb);
    }
    
    //initialize helper class
    if (!class_exists('awshelper'))
    {
        class awshelper extends corebase
        {   
             //this file version
            public $metadata; //execution related metadata
            public $method; // POST | GET
            public $paramerror;
            
            public $version;

            /*
                $data is given in by bootstrap parameter
                $paramsyntax - use only if you want to test your own parameter rules
                $config - use only if you want to provide your own configuration
                
                return $helper->doError($helper, ['request-function' => ['type' => 'string', 'required' => 1]]);
            */
            function __construct(&$data, $params = NULL, $config = NULL)
            {
                $this->version = '1.1.0';
                
                //constructing basic class metadata
                $this->constructMetadata($params);

                //read in global configuration
                $this->readConfig($config);
                
                // read request headers and see what we get from there
                $this->readHeaders($data);
                
                //read request body
                $this->readBody($data);
                
                //clean and validate parameters
                $data = $this->performParameterCheck($data);
            }
            
            
            //wrapper to call all this class private functions with ['command' => 'funcname'()]
            /*
                returns result in case of error only.
                if no error, $successresult will return the message from the function
            */          
            function doExecute(&$successresult, $data) 
            {
                if (!empty($data['command'])) $command = '__' . $data['command'];
                else return sprintf('function command was not provided');
                
                //see if we need to establish or maintain a database connection
                //it happens if user sents a protected parameter 'connection' in payload
                if (
                    isset($data['parameters']['connection']) && 
                    empty($this->metadata['connections'][$data['parameters']['connection']]['object']) && 
                    ($data['command'] != 'doEstablishSQLConnection')
                )
                {
                    if ($err = $this->doExecute(${$output = 'conn'}, [
                        'command' => 'doEstablishSQLConnection', 
                        'parameters' => [
                            'connection' => $data['parameters']['connection']
                        ]
                    ])) return $err;
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
                                    //class not found
                                    if (!class_exists($module)) {
                                        return sprintf('class %s was requested but not found', $module);
                                    }
                                    //create class and assign
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
                    } else {
                        //unable to find the requested method from any of the classes
                        return 'method $' . get_class($this) . '->' . $command . '() does not exist';
                    }
                } else {

                    //we call method from this class
                    try {
                        $result = call_user_func_array(array($this, $command), array($data['parameters']));
                    }
                    catch(Exception $e) { return $helper->doError($e->getMessage()); }
                    
                    if (is_object($result))
                    {
                        //if we are returning object from protected function
                        $successresult = $result;
                        return false;
                    } else {
                        //if result from function is string
                        if (!isset($result) or empty($result)) return sprintf('function doExecute->%s() returned empty result', $command);
                        $result = json_decode($result, 1);
                        if (!isset($result) or empty($result)) return sprintf('function doExecute->%s() returned NON-JSON result', $command);                        
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
            
            
            /*
                function returns mysql object if asked from doExecute
                $output = 'conn';
                if ($err = $helper->doExecute(${$output}, [
                    'command' => 'getConnection',
                    'parameters' => [
                        'connection' => 'core'
                    ]
                ])) return $helper->doError($err);
                
                return $helper->doOk(print_r($conn, 1));       
                
            */
            protected function __getConnection($data) 
            {
                $conn_slug = $data['connection'];
                if (empty($connpool = $this->metadata['connections']))
                    return $this->doError(sprintf('Unable to Access Connection Pool: $helper->metadata["connections"]'));
                    
                if (empty($connection = $connpool[$conn_slug]))
                    return $this->doError(sprintf('Unable to Access Connection Pool Connection: $helper->metadata["connections"]["%s"]', $conn_slug));
                    
                if (empty($connection['established']))
                    return $this->doError(sprintf('Connection Pool Connection is not Established: $helper->metadata["connections"]["%s"]', $conn_slug));
                
                if (!is_object($connection['object']))
                    return $this->doError(sprintf('Connection is Promesed to be Established but No SQL Object: $helper->metadata["connections"]["%s"]', $conn_slug));
                    
                return $connection['object'];
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
                if (empty($data[$tf = 'region'])) {
                    return $this->doError(sprintf('required parameter is missing: [ %s ]', $tf));   
                }                
                if (!$data[$tf = 'connection']) return $this->doError('required parameter missing: [' . $tf . ']');
                
                //get url for the function
                if ($err = $this->doExecute(${$output = 'function_url'}, [
                    'command' => 'mysql_getSingleCellValue',
                    'parameters' => [
                        'tablename' => '_aws_' . $data['region'] . '_functions',
                        'where' => [
                            'description' => $data['endpoint'],
                            'region' => $data['region']
                        ],
                        'column' => 'function_url',
                        'singleexpected' => 1,
                        'connection' => 'core'
                    ]
                ])) { 
                    if ($err == 'NULL') return $this->doError(sprintf('Unable to retrieve Amazon URL for Function %s', $data['endpoint']));
                    else return $this->doError($err); 
                }
                
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
                    return $this->doError('no connection parameter defined');
                }
                
                if (isset($this->metadata['connections'][$data['connection']]['object'])) {
                    return $this->doOk(sprintf('connection was already established and can be found @ $helper->conn[%s]', $data['connection']));
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
                
                mysqli_report(MYSQLI_REPORT_ALL ^ MYSQLI_REPORT_INDEX);
                
                if (!$conn = mysqli_connect(
                    $cs['hostname'], 
                    $cs['username'], 
                    $cs['password'], 
                    $cs['database']
                )) {
                    return $this->doError(sprintf('unable to open MySQL connection [%s] %s', $data['connection'], print_r($cs, 1)));
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
