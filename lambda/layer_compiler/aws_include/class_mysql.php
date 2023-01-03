<?php

	/* class responsible for all the mysql interactions */
	if (!class_exists('mysql'))
	{
		class mysql {
			
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
                ])) return $helper->doError($err);
                
                return $helper->doError($res);			    
			*/                
			function __mysql_doQuery($data, $helper) 
			{
			    /* we remove those later */
			    if (!$data[$tf = 'connection']) return $helper->doError('required parameter missing: [' . $tf . ']');
			    if (!$data[$tf = 'keyholder']) return $helper->doError('required parameter missing: [' . $tf . ']');
			    if (!$data[$tf = 'query']) return $helper->doError('required parameter missing: [' . $tf . ']');
			    /* end of removing those */
			    
			    //check if connection is established
			    if (!$helper->metadata['connections'][$data['connection']]['established']) {
			        return $helper->doError('connection to database is not established: %s', $data['connection']);
			    } else {
			        $conn = $helper->metadata['connections'][$data['connection']]['object'];
			    }
			    
			    if (!$res = $conn->query($data['query'])) {
			        return $helper->doError('mysql error: ' . $conn->error . 'when running a query: <br><br>' . $data['query']);
			    }
			    
                //no records return
                if (!mysqli_num_rows($res)) {
                    if (isset($data['no-rows-error']) && $data['no-rows-error'] == true) {
                        return $helper->doError('query returned zero results');
                    } else {
                        return $helper->doOk(0);
                    }                    
                }
                
                while ($row = mysqli_fetch_assoc($res))
                {
                    //no keyholder field in resultset
                    if (!isset($row[$data['keyholder']])) {
                        return $helper->doError(sprintf('keyholder "%s" is missing from resultset: [ %s ]', $data['keyholder'], implode(' | ', array_keys($row) )));
                    }
                    
                    if (!empty($result[$row[$data['keyholder']]])) {
                        return $helper->doError(sprintf('keyholder "%s" with value "%s" is already existing in result. please choose unique id from resultset: [ %s ]', $data['keyholder'], $row[$data['keyholder']], implode(' | ', array_keys($row) )));
                    }
                    
                    $result[$row[$data['keyholder']]] = $row;
                }
                
                return $helper->doOk($result);
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
                ])) return $helper->doError($err);
                
                return $helper->doError($res);			
			*/
			function __mysql_getCount($data, $helper)
			{
			    //check if connection is established
			    if (!$helper->metadata['connections'][$data['connection']]['established']) {
			        return $helper->doError('connection to database is not established: %s', $data['connection']);
			    } else {
			        $conn = $helper->metadata['connections'][$data['connection']]['object'];
			    }
			    
			    //continue with usual execution
			    $data['keyholder'] = 'cnt';
			    
			    if (!$res = $conn->query($data['query'])) {
			        return $helper->doError('mysql error: ' . $conn->error . 'when running a query: <br><br>' . $data['query']);
			    }
			    
			    if (!mysqli_num_rows($res)) {
			        return $helper->doOk('0');
			    }
			    
			    $row = mysqli_fetch_assoc($res);
			    if (!isset($row[$data['keyholder']])) {
			        return $helper->doError(sprintf('keyholder "%s" is missing from resultset: [ %s ]', $data['keyholder'], implode(' | ', array_keys($row) )));
			    }
			    
			    return $helper->doOk($row['cnt']);
			    
			}
			
            /*
                function will either insret or update recordset

                sample usage:
                //enter or update row in _aws_layers_table
                if ($err = $test->doExecute(${$output = 'query'}, [
                    'command' => 'mysql_doInsertOrUpdate',
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
			function __mysql_doInsertOrUpdate($data, $helper)
			{
			    //check if connection is established
			    if (!$helper->metadata['connections'][$data['connection']]['established']) {
			        return $helper->doError('connection to database is not established: %s', $data['connection']);
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
			    if (empty($ini = strpos($update, $start = ' SET '))) return $helper->doError('trim error');
			    $ini += strlen($start);
			    $len = strpos($update, $end = ' WHERE ', $ini) - $ini;

			    //build on duplicate query
			    $querypool[] = $insert;
			    $querypool[] = 'ON DUPLICATE KEY UPDATE ' . trim(substr($update, $ini, $len)) . ';';
			    
			    $query = implode("\n", $querypool);

			    if (!$res = $conn->multi_query($query)) {
				    while ($conn->next_result()) { if (!$conn->more_results()) break; }
			        return $helper->doError('mysql error: ' . $conn->error . 'when running a query: <br><br>' . $data['query']);
			    }			    
			    
			    // flush multi_queries
			    while ($conn->next_result()) { if (!$conn->more_results()) break; }
			    
			    //get touched id
			    return $helper->doOk(mysqli_insert_id($conn));
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
                ])) return $helper->doError($err);
                return $helper->doOk($query);
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
                
                return $helper->doOk($query);			    
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
			function __mysql_constructUpdateQuery($data, $helper)
			{
                if (!$data[$tf = 'tablename']) return $helper->doError('required parameter missing: [' . $tf . ']');
                
                foreach ($data['keys'] as $void => $key)
                {
                    if (!isset($data['fields'][$key])) return $helper->doError('fields[' . $key . '] is not defined in input params');
                    $wherequery[] = sprintf('`%s` = \'%s\'', $key, addslashes($data['fields'][$key]));
                    unset($data['fields'][$key]);
                }
                $where = implode(' AND ', $wherequery);
                if (!$where) {
                    return $helper->doError('where clause is not defined for count query.');
                }
                
                $packed = array();
                foreach($data['fields'] as $k => $v) {
                    if ($v != '') $v = addslashes($v);                  
                    if (!is_array($v)) $packed[] = sprintf("`%s` = '%s'", addslashes($k), $v);
                }
                
                if (!count($packed)) {
                    return $helper->doError('no parameters to update in SQL query');
                }
                
                $query = sprintf("UPDATE `%s` SET %s WHERE %s", $data['tablename'], implode(', ', $packed), $where);
                
                //for mysql functions we use !!!NOW()!!!
                //remove escaping
                $query = str_replace('!!!\'', '', str_replace('\'!!!', '', $query));

                return $helper->doOk($query);				
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
                ])) return $helper->doError($err);                
            */			
			function __mysql_getSingleCellValue($data, $helper) {
			    //check if connection is established
			    if (!$helper->metadata['connections'][$data['connection']]['established']) {
			        return $helper->doError('connection to database is not established: %s', $data['connection']);
			    } else {
			        $conn = $helper->metadata['connections'][$data['connection']]['object'];
			    }
			    
                //kaob ära, kui me saame kasutada parameetri kontrollimise funktsiooni
                if (!$data[$tf = 'connection']) return $helper->doError('required parameter missing: [' . $tf . ']');
                if (!$data[$tf = 'tablename']) return $helper->doError('required parameter missing: [' . $tf . ']');
                if (!count($data[$tf = 'where'])) return $helper->doError('array is not defined: [' . $tf . ']');
                
                if (!isset($data['column']) or $data['column'] == '') {
                    return $helper->doError('"column" input parameter is not defined for $mysql->__mysql_getSingleCellValue()');
                }
                foreach ($data['where'] as $key => $value) {
                    $wherequery[] = sprintf('`%s` = \'%s\'', $key, $value);
                }
                $where = implode(' AND ', $wherequery);
                if (!$where) {
                    return $helper->doError('where clause is not defined for count query.');
                }
                
                $query = sprintf("SELECT * FROM `%s` WHERE %s", $data['tablename'], $where);

                if (!$res = $conn->query($query)) {
                    return $helper->doError($conn->error);
                }
                
                if (empty(mysqli_num_rows($res))) {
                    return $helper->doError('no records retrieved from $mysql->__mysql_getSingleCellValue()');
                }
                
                if (mysqli_num_rows($res) > 1 && !empty($data['singleexpected'])) {
                    return $helper->doError(sprintf('$mysql->__mysql_getSingleCellValue() returned more than one row: (%d rows)', mysqli_num_rows($res)));
                }
                
                $row = mysqli_fetch_assoc($res);
                
                if (!array_key_exists($data['column'], $row)) {
                    return $helper->doError(sprintf('$mysql->__mysql_getSingleCellValue() result didnt consist field %s [ %s ]', $data['column'], implode(' | ', array_key_exists($row))));
                }
                
                $value = $row[$data['column']];
                if ($value == '') {
                    return $helper->doError('NULL');
                }
                
                return $helper->doOk($value);			    
			}
			
			
		}
	}

?>
