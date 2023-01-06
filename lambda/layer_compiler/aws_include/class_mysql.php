<?php

    /* class responsible for all the mysql interactions */
    if (!class_exists('mysql'))
    {
        class mysql extends corebase {
            
            /*
                for making simple query
                sample usage:
                
                $output = 'res';
                if ($err = $helper->doExecute(${$output}, [
                    'command' => 'mysql_doQuery',
                    'parameters' => [
                        'connection' => 'core',
                        'query' => "select * from `_aws_eu-north-1_functions`",
                        'keyholder' => 'aws_function_id'
                    ]
                ])) return $helper->err($err);
                
                return $helper->err($res);              
            */                
            function __mysql_doQuery($data, $helper) 
            {
                /* we remove those later */
                if (!$data[$tf = 'connection']) return $helper->err('required parameter missing: [' . $tf . ']');
                if (!$data[$tf = 'keyholder']) return $helper->err('required parameter missing: [' . $tf . ']');
                if (!$data[$tf = 'query']) return $helper->err('required parameter missing: [' . $tf . ']');
                /* end of removing those */
                
                //check if connection is established
                if (!$helper->metadata['connections'][$data['connection']]['established']) {
                    return $helper->err('connection to database is not established: %s', $data['connection']);
                } else {
                    $conn = $helper->metadata['connections'][$data['connection']]['object'];
                }
                
                if (!$res = $conn->query($data['query'])) {
                    return $helper->err('mysql error: ' . $conn->error . 'when running a query: <br><br>' . $data['query']);
                }
                
                //no records return
                if (!mysqli_num_rows($res)) {
                    if (isset($data['no-rows-error']) && $data['no-rows-error'] == true) {
                        return $helper->err('query returned zero results');
                    } else {
                        return $helper->ok(0);
                    }                    
                }
                
                while ($row = mysqli_fetch_assoc($res))
                {
                    //no keyholder field in resultset
                    if (!isset($row[$data['keyholder']])) {
                        return $helper->err(sprintf('keyholder "%s" is missing from resultset: [ %s ]', $data['keyholder'], implode(' | ', array_keys($row) )));
                    }
                    
                    if (!empty($result[$row[$data['keyholder']]])) {
                        return $helper->err(sprintf('keyholder "%s" with value "%s" is already existing in result. please choose unique id from resultset: [ %s ]', $data['keyholder'], $row[$data['keyholder']], implode(' | ', array_keys($row) )));
                    }
                    
                    $result[$row[$data['keyholder']]] = $row;
                }
                
                return $helper->ok($result);
            }
            
            

            /*
                for making query where we dont expect any result
                sample usage:
                
                $output = 'void';
                if ($err = $helper->doExecute(${$output}, [
                    'command' => 'mysql_doVoidQuery',
                    'parameters' => [
                        'connection' => 'core',
                        'query' => "select * from `_aws_eu-north-1_functions`"
                    ]
                ])) return $helper->err($err);
                
                return $helper->err($res);              
            */
            function __mysql_doVoidQuery($data, $helper) 
            {
                //if(!isset(debug_backtrace()[1]['class']) || !in_array(debug_backtrace()[1]['class'], ['awshelpers']))
                  //  return $helper->err('access class prohibited');
                
                /* we remove those later */
                if (!$data[$tf = 'connection']) return $helper->err('required parameter missing: [' . $tf . ']');
                if (!$data[$tf = 'query']) return $helper->err('required parameter missing: [' . $tf . ']');
                /* end of removing those */
                
                //check if connection is established
                if (!$helper->metadata['connections'][$data['connection']]['established']) {
                    return $helper->err('connection to database is not established: %s', $data['connection']);
                } else {
                    $conn = $helper->metadata['connections'][$data['connection']]['object'];
                }
                
                if (!$res = $conn->query($data['query'])) {
                    return $helper->err('mysql error: ' . $conn->error . 'when running a query: <br><br>' . $data['query']);
                }
                
                return $helper->ok('query completed without errors');
            }
            
            
            /* function is expecting single row with field "cnt" 
                sample usage:
                
                $output = 'res';
                if ($err = $helper->doExecute(${$output}, [
                    'command' => 'mysql_doQuery',
                    'parameters' => [
                        'connection' => 'core',
                        'query' => "select count(*) as `cnt` from `_aws_eu-north-1_functions`"
                    ]
                ])) return $helper->err($err);
                
                return $helper->err($res);          
            */
            function __mysql_getCount($data, $helper)
            {
                //check if connection is established
                if (!$helper->metadata['connections'][$data['connection']]['established']) {
                    return $helper->err('connection to database is not established: %s', $data['connection']);
                } else {
                    $conn = $helper->metadata['connections'][$data['connection']]['object'];
                }
                
                //continue with usual execution
                $data['keyholder'] = 'cnt';
                
                if (!$res = $conn->query($data['query'])) {
                    return $helper->err('mysql error: ' . $conn->error . 'when running a query: <br><br>' . $data['query']);
                }
                
                if (!mysqli_num_rows($res)) {
                    return $helper->ok('0');
                }
                
                $row = mysqli_fetch_assoc($res);
                if (!isset($row[$data['keyholder']])) {
                    return $helper->err(sprintf('keyholder "%s" is missing from resultset: [ %s ]', $data['keyholder'], implode(' | ', array_keys($row) )));
                }
                
                return $helper->ok($row['cnt']);
                
            }
            
            /*
                function will either insret or update recordset

                sample usage:
                //enter or update row in _aws_layers_table
                if ($err = $helper->doExecute(${$output = 'query'}, [
                    'command' => 'mysql_doInsertOrUpdate',
                    'parameters' => [
                        'tablename' => '_aws_layers',
                        'fields' => $modify_datapack,
                        'auto-increment-field' => $field_you_keep_primary,
                        'keys' => [
                            'aws_layer_name'
                        ],
                        'connection' => 'core',
                    ]
                ])) return $helper->err($err);              
            */          
            function __mysql_doInsertOrUpdate($data, $helper)
            {
                //get rid of later
                if (empty($data['auto-increment-field'])) return $helper->err('$data[auto-increment-field] is required but not defined in parameters');
                
                //check if connection is established
                if (!$helper->metadata['connections'][$data['connection']]['established']) {
                    return $helper->err('connection to database is not established: %s', $data['connection']);
                } else {
                    $conn = $helper->metadata['connections'][$data['connection']]['object'];
                }
                
                //continue with usual execution
                $querypool[] = sprintf('SET @NEW_AI = (SELECT MAX(`%s`) + 1 FROM `%s`);', $data['auto-increment-field'], $data['tablename']);
                $querypool[] = sprintf("SET @ALTER_SQL = CONCAT('ALTER TABLE `%s` AUTO_INCREMENT =', @NEW_AI);", $data['tablename']);
                $querypool[] = sprintf("PREPARE NEWSQL FROM @ALTER_SQL; EXECUTE NEWSQL;");
                
                //insert query
                
                //insert
                $insert = json_decode($e = $this->__mysql_constructInsertQuery($data, $helper), 1);
                if ($insert['statusCode'] != 200) return $e;
                $insert = sprintf(print_r(json_decode($insert['body'], 1)['data']['result'], 1));               
                
                //update
                $update = json_decode($e = $this->__mysql_constructUpdateQuery($data, $helper), 1);
                if ($update['statusCode'] != 200) return $e;
                $update = sprintf(print_r(json_decode($update['body'], 1)['data']['result'], 1));

                //modify update query for the multiquery                
                if (empty($ini = strpos($update, $start = ' SET '))) return $helper->err('trim error');
                $ini += strlen($start);
                $len = strpos($update, $end = ' WHERE ', $ini) - $ini;

                //build on duplicate query
                $querypool[] = $insert;
                $querypool[] = 'ON DUPLICATE KEY UPDATE ' . trim(substr($update, $ini, $len)) . ';';
                
                $query = implode("\n", $querypool);

                if (!$res = $conn->multi_query($query)) {
                    while ($conn->next_result()) { if (!$conn->more_results()) break; }
                    return $helper->err('mysql error: ' . $conn->error . 'when running a query: <br><br>' . $data['query']);
                }               
                
                // flush multi_queries
                while ($conn->next_result()) { if (!$conn->more_results()) break; }
                
                //get touched id
                return $helper->ok(mysqli_insert_id($conn));
            }


            /*
                inserting a new record to table
                sample usage:

                if ($err = $helper->doExecute(${$output = 'query'}, [
                    'command' => 'mysql_constructInsertQuery',
                    'parameters' => [
                        'tablename' => '_aws_layers',
                        'fields' => [
                            'function_id' => 1,
                            'function_data' => 'hello',
                            'third' => 'yeah'
                        ]
                    ]
                ])) return $helper->err($err);
                return $helper->ok($query);
            */          
            function __mysql_constructInsertQuery($data, $helper) {
                $packed = array();
                foreach($data['fields'] as $k => $v) {
                    if (!is_array($v)) $packed[$k] = addslashes($v);
                }
                
                $key = array_keys($packed);
                $val = array_values($packed);
                $query = "INSERT INTO `" . $data['tablename'] . "` (`" . implode('`, `', $key) . "`) " . "VALUES ('" . implode("', '", $val) . "')";
                
                //for mysql functions we use !!!NOW()!!!
                $query = str_replace('!!!\'', '', str_replace('\'!!!', '', $query));
                
                return $helper->ok($query);               
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
                ])) return $helper->err($err);
                return $helper->ok($query);
            */
            function __mysql_constructUpdateQuery($data, $helper)
            {
                if (!$data[$tf = 'tablename']) return $helper->err('required parameter missing: [' . $tf . ']');
                
                foreach ($data['keys'] as $void => $key)
                {
                    if (!isset($data['fields'][$key])) return $helper->err('fields[' . $key . '] is not defined in input params');
                    $wherequery[] = sprintf('`%s` = \'%s\'', $key, addslashes($data['fields'][$key]));
                    unset($data['fields'][$key]);
                }
                $where = implode(' AND ', $wherequery);
                if (!$where) {
                    return $helper->err('where clause is not defined for count query.');
                }
                
                $packed = array();
                foreach($data['fields'] as $k => $v) {
                    if ($v != '') $v = addslashes($v);                  
                    if (!is_array($v)) $packed[] = sprintf("`%s` = '%s'", addslashes($k), $v);
                }
                
                if (!count($packed)) {
                    return $helper->err('no parameters to update in SQL query');
                }
                
                $query = sprintf("UPDATE `%s` SET %s WHERE %s", $data['tablename'], implode(', ', $packed), $where);
                
                //for mysql functions we use !!!NOW()!!!
                //remove escaping
                $query = str_replace('!!!\'', '', str_replace('\'!!!', '', $query));

                return $helper->ok($query);               
            }
            

            /*
                get the id row from provided parameters
                
                sample usage:
                if ($err = $test->doExecute(${$output = 'fk_layer_id'}, [
                    'command' => 'getIdFromQuery',
                    'parameters' => [
                        'tablename' => '_aws_' . $aws_region . '_layers',
                        'where' => [
                            'aws_layer_name' => $layername
                        ],
                        'column' => 'aws_layer_id',
                        'singleexpected' => 1,
                        'connection' => 'core'
                    ]
                ])) return $helper->err($err);                
            */          
            function __mysql_getSingleCellValue($data, $helper) {
                //check if connection is established
                if (!$helper->metadata['connections'][$data['connection']]['established']) {
                    return $helper->err('connection to database is not established: %s', $data['connection']);
                } else {
                    $conn = $helper->metadata['connections'][$data['connection']]['object'];
                }
                
                //kaob ära, kui me saame kasutada parameetri kontrollimise funktsiooni
                if (!$data[$tf = 'connection']) return $helper->err('required parameter missing: [' . $tf . ']');
                if (!$data[$tf = 'tablename']) return $helper->err('required parameter missing: [' . $tf . ']');
                if (!count($data[$tf = 'where'])) return $helper->err('array is not defined: [' . $tf . ']');
                
                if (!isset($data['column']) or $data['column'] == '') {
                    return $helper->err('"column" input parameter is not defined for $mysql->__mysql_getSingleCellValue()');
                }
                foreach ($data['where'] as $key => $value) 
                {
                    if (is_null($value) or empty(trim($value))) {
                        return $helper->err(
                            sprintf('when performing getSingleCellValue() $data["where"]["%s"] key had no value. use parameter empty-value-ok to bypass this message', $key)
                        );
                    }
                    $wherequery[] = sprintf('`%s` = \'%s\'', $key, $value);
                }
                $where = implode(' AND ', $wherequery);
                if (!$where) {
                    return $helper->err('where clause is not defined for count query.');
                }
                
                $query = sprintf("SELECT * FROM `%s` WHERE %s", $data['tablename'], $where);

                if (!$res = $conn->query($query)) {
                    return $helper->err($conn->error);
                }
                
                if (empty(mysqli_num_rows($res))) {
                    return $helper->err('no records retrieved from $mysql->__mysql_getSingleCellValue():');
                }
                
                if (mysqli_num_rows($res) > 1 && !empty($data['singleexpected'])) {
                    return $helper->err(sprintf('$mysql->__mysql_getSingleCellValue() returned more than one row: (%d rows)', mysqli_num_rows($res)));
                }
                
                $row = mysqli_fetch_assoc($res);
                
                if (!array_key_exists($data['column'], $row)) {
                    return $helper->err(sprintf('$mysql->__mysql_getSingleCellValue() result didnt consist field %s [ %s ]', $data['column'], implode(' | ', array_key_exists($row))));
                }
                
                $value = $row[$data['column']];
                if ($value == '') {
                    return $helper->err('NULL');
                }
                
                return $helper->ok($value);               
            }


            /*
                function is updating softdelete flag.
                
                sample usage:
                    if ($err = $helper->doExecute(${$output = 'void'}, [
                        'command' => 'mysql_doSoftDelete',
                        'parameters' => [
                            'connection' => 'core',
                            'tablename' => 'sample_table_name',
                            'keyholder' => 'is_deleted',
                            'where' => [
                                'function_name' => $function
                            ]
                        ]
                    ])) return $helper->err($err);
                
            */
            
            function __mysql_doSoftDelete($data, $helper) {
                //check if connection is established
                if (!$helper->metadata['connections'][$data['connection']]['established']) {
                    return $helper->err('connection to database is not established: %s', $data['connection']);
                } else {
                    $conn = $helper->metadata['connections'][$data['connection']]['object'];
                }
                
                foreach ($data['where'] as $key => $value) {
                    $wherequery[] = sprintf('`%s` = \'%s\'', $key, $value);
                }
                $where = implode(' AND ', $wherequery);
                if (!$where) {
                    return $helper->err('where clause is not defined for count query.');
                }
                
                $query = "UPDATE `%s` SET `%s` = 1 WHERE %s";
                $makequery = sprintf($query, $data['tablename'], $data['keyholder'], $where);
                if (!$conn->query($makequery)) {
                    return $helper->err($conn->error);
                }
                
                return $helper->ok(mysqli_insert_id($conn));
            }       
            
        }
    }

?>
