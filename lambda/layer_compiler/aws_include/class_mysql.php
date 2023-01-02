<?php

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
			    if (!$data[$tf = 'connection']) return $this->doError('required parameter missing: [' . $tf . ']');
			    if (!$data[$tf = 'keyholder']) return $this->doError('required parameter missing: [' . $tf . ']');
			    if (!$data[$tf = 'query']) return $this->doError('required parameter missing: [' . $tf . ']');
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
			    
			    $insert = sprintf(print_r(json_decode(json_decode($this->__mysql_constructInsertQuery($data, $helper), 1)['body'], 1)['data']['result'], 1));
			    $update = sprintf(print_r(json_decode(json_decode($this->__mysql_constructUpdateQuery($data, $helper), 1)['body'], 1)['data']['result'], 1));
			    
			    $querypool[] = $insert;

			    $string = ' ' . $update;
			    $ini = strpos($string, $start = ' SET ');
			    if ($ini == 0) return '';
			    $ini += strlen($start);
			    $len = strpos($string, $end = ' WHERE ', $ini) - $ini;
			    $querypool[] = 'ON DUPLICATE KEY UPDATE ' . trim(substr($string, $ini, $len));
			    
			    $query = implode("\n", $querypool);

			    if (!$res = $conn->multi_query($query)) {
			        return $helper->doError('mysql error: ' . $conn->error . 'when running a query: <br><br>' . $data['query']);
			    }			    
			    
                return $helper->doOk(1);
                /*
                
                
                ON DUPLICATE KEY UPDATE `the_col` = "the_value";                
                */
			    
			    
			    return $helper->doError('welcome from function');
			}
			
			function __mysql_constructInsertQuery($data, $helper) {
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
                
                return $helper->doOk($query);			    
			}
			
			function __mysql_constructUpdateQuery($data, $helper) {
                if (!$data[$tf = 'tablename']) return $this->doError('required parameter missing: [' . $tf . ']');
                
                foreach ($data['keys'] as $void => $key) {
                    if (!isset($data['fields'][$key])) return $this->doError('fields[' . $key . '] is not defined in input params');
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
			
		}
	}

?>
