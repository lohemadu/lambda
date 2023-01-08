<?php

    /* class responsible for all the mysql interactions */
    if (!class_exists('mysql'))
    {
        class mysql extends corebase
        {
            
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
            

            public function __mysql_doQuery($data) 
            {
                $conn = $data['connection'];
                if (!is_object($conn)) return $this->innererr('Required parameter "connection" was not provided');
                
                if (!$res = $conn->query($data['query'])) {
                    return $this->innererr('mysql error: ' . $conn->error . 'when running a query: <br><br>' . $data['query']);
                }
                
                //no records return
                if (!mysqli_num_rows($res)) {
                    if (isset($data['empty-is-error']) && $data['empty-is-error'] == true) {
                        return $this->innererr('Query returned 0 results, unable to continue');
                    } else {
                        return $this->innerok([]);
                    }                    
                }
                
                while ($row = mysqli_fetch_assoc($res))
                {
                    //no keyholder field in resultset
                    if (!isset($row[$data['keyholder']])) {
                        return $this->innererr(sprintf('keyholder "%s" is missing from resultset: [ %s ]', $data['keyholder'], implode(' | ', array_keys($row) )));
                    }
                    
                    if (!empty($result[$row[$data['keyholder']]])) {
                        return $this->innererr(sprintf('keyholder "%s" with value "%s" is already existing in result. please choose unique id from resultset: [ %s ]', $data['keyholder'], $row[$data['keyholder']], implode(' | ', array_keys($row) )));
                    }
                    
                    $result[$row[$data['keyholder']]] = $row;
                }
                
                return $this->innerok($result);
            }


            public function __mysql_doVoidQuery($data) 
            {
                $conn = $data['connection'];
                if (!is_object($conn)) return $this->innererr('Required parameter "connection" was not provided');
                
                if (!$res = $conn->query($data['query'])) {
                    return $this->innererr('mysql error: ' . $conn->error . 'when running a query: <br><br>' . $data['query']);
                }
                
                return $this->innerok('query completed without errors');
            }  


            public function __mysql_getCount($data)
            {
                $conn = $data['connection'];
                if (!is_object($conn)) return $this->innererr('Required parameter "connection" was not provided');
                
                //continue with usual execution
                $data['keyholder'] = 'cnt';
                
                if (!$res = $conn->query($data['query'])) {
                    return $this->innererr('mysql error: ' . $conn->error . 'when running a query: <br><br>' . $data['query']);
                }
                
                if (!mysqli_num_rows($res)) {
                    return $this->innerok('0');
                }
                
                $row = mysqli_fetch_assoc($res);
                if (!isset($row[$data['keyholder']])) {
                    return $this->innererr(sprintf('keyholder "%s" is missing from resultset: [ %s ]', $data['keyholder'], implode(' | ', array_keys($row) )));
                }
                
                return $this->innerok($row['cnt']);                
            }      
            

            public function __mysql_doInsertOrUpdate($data)
            {
                $conn = $data['connection'];
                if (!is_object($conn)) return $this->innererr('Required parameter "connection" was not provided');
                
                //continue with usual execution
                $querypool[] = sprintf('SET @NEW_AI = (SELECT MAX(`%s`) + 1 FROM `%s`);', $data['auto-increment-field'], $data['tablename']);
                $querypool[] = sprintf("SET @ALTER_SQL = CONCAT('ALTER TABLE `%s` AUTO_INCREMENT =', @NEW_AI);", $data['tablename']);
                $querypool[] = sprintf("PREPARE NEWSQL FROM @ALTER_SQL; EXECUTE NEWSQL;");
                
                //insert query
                
                //insert
                $e = $this->doConstructInsert($data);
                if (isset($e['inner']['error'])) {
                    return $this->innererr($e['message']);
                } else $insert = $e['message'];

                //update
                $e = $this->doConstructUpdate($data);
                if (isset($e['inner']['error'])) {
                    return $this->innererr($e['message']);
                } else $update = $e['message'];

                //modify update query for the multiquery                
                if (empty($ini = strpos($update, $start = ' SET '))) return $this->innererr('trim error');
                $ini += strlen($start);
                $len = strpos($update, $end = ' WHERE ', $ini) - $ini;

                //build on duplicate query
                $querypool[] = $insert;
                $querypool[] = 'ON DUPLICATE KEY UPDATE ' . trim(substr($update, $ini, $len)) . ';';
                
                $query = implode("\n", $querypool);

                if (!$res = $conn->multi_query($query)) {
                    while ($conn->next_result()) { if (!$conn->more_results()) break; }
                    return $this->innererr('mysql error: ' . $conn->error . 'when running a query: <br><br>' . $data['query']);
                }               
                
                // flush multi_queries
                while ($conn->next_result()) { if (!$conn->more_results()) break; }
                
                //get touched id
                return $this->innerok(mysqli_insert_id($conn));
            }  


            function __mysql_doSoftDelete($data)
            {
                $conn = $data['connection'];
                if (!is_object($conn)) return $this->innererr('Required parameter "connection" was not provided');
                
                //construct where
                foreach ($data['where'] as $key => $value)
                {
                    if (is_array($value))
                    {
                        if (!$this->hasElements($value)) continue;
                        $imploded = implode('\',\'', $value);
                        if (!empty($imploded)) {
                            $wherequery[] = sprintf('`%s` IN (\'%s\')', $key, $imploded);
                        }
                    } else $wherequery[] = sprintf('`%s` = \'%s\'', $key, $value);
                }
                
                foreach ($data['wherenot'] as $key => $value)
                {
                    if (is_array($value))
                    {
                        if (!$this->hasElements($value)) continue;
                        $imploded = implode('\',\'', $value);
                        if (!empty($imploded)) {
                            $wherequery[] = sprintf('`%s` NOT IN (\'%s\')', $key, $imploded);
                        }
                    } else $wherequery[] = sprintf('`%s` <> \'%s\'', $key, $value);
                }                
                
                if (!$this->hasElements($wherequery)) return $this->innererr('when constructing soft delete query we found no where or wherenot pieces');
                
                $where = implode(' AND ', $wherequery);
                if (!$where) {
                    return $this->innererr('where clause is not defined for count query.');
                }
                
                $query = "UPDATE `%s` SET `%s` = 1 WHERE %s";
                $makequery = sprintf($query, $data['tablename'], $data['flagholder'], $where);
                if (!$conn->query($makequery)) {
                    return $this->innererr($conn->error);
                }
                
                return $this->innerok(mysqli_insert_id($conn));
            } 


            function __mysql_getSingleCellValue($data)
            {
                $conn = $data['connection'];
                if (!is_object($conn)) return $this->innererr('Required parameter "connection" was not provided');
                
                foreach ($data['where'] as $key => $value) 
                {
                    if (is_null($value) or empty(trim($value))) {
                        return $this->innererr(
                            sprintf('when performing getSingleCellValue() $data["where"]["%s"] key had no value. use parameter empty-value-ok to bypass this message', $key)
                        );
                    }
                    $wherequery[] = sprintf('`%s` = \'%s\'', $key, $value);
                }
                $where = implode(' AND ', $wherequery);
                if (!$where) {
                    return $this->innererr('where clause is not defined for count query.');
                }
                
                $query = sprintf("SELECT * FROM `%s` WHERE %s", $data['tablename'], $where);

                if (!$res = $conn->query($query)) {
                    return $this->innererr($conn->error);
                }
                
                if (empty(mysqli_num_rows($res))) {
                    return $this->innererr('no records retrieved from $mysql->__mysql_getSingleCellValue():');
                }
                
                if (mysqli_num_rows($res) > 1 && !empty($data['singleexpected'])) {
                    return $this->innererr(sprintf('$mysql->__mysql_getSingleCellValue() returned more than one row: (%d rows)', mysqli_num_rows($res)));
                }
                
                $row = mysqli_fetch_assoc($res);
                
                if (!array_key_exists($data['column'], $row)) {
                    return $this->innererr(sprintf('$mysql->__mysql_getSingleCellValue() result didnt consist field %s [ %s ]', $data['column'], implode(' | ', array_key_exists($row))));
                }
                
                $value = $row[$data['column']];
                if ($value == '') {
                    return $this->innererr('NULL');
                }
                
                return $this->innerok($value);               
            }


            //building insert query based on data
            private function doConstructInsert($data)
            {
                $packed = array();
                foreach($data['fields'] as $k => $v) {
                    if (!is_array($v)) $packed[$k] = addslashes($v);
                }
                
                $key = array_keys($packed);
                $val = array_values($packed);
                $query = "INSERT INTO `" . $data['tablename'] . "` (`" . implode('`, `', $key) . "`) " . "VALUES ('" . implode("', '", $val) . "')";
                
                //for mysql functions we use !!!NOW()!!!
                $query = str_replace('!!!\'', '', str_replace('\'!!!', '', $query));
                
                return $this->innerok($query);               
            }

            //building update query based on data
            private function doConstructUpdate($data)
            {
                foreach ($data['keys'] as $void => $key)
                {
                    if (!isset($data['fields'][$key])) return $this->innererr('fields[' . $key . '] is not defined in input params');
                    $wherequery[] = sprintf('`%s` = \'%s\'', $key, addslashes($data['fields'][$key]));
                    unset($data['fields'][$key]);
                }
                $where = implode(' AND ', $wherequery);
                if (!$where) {
                    return $this->innererr('where clause is not defined for count query.');
                }
                
                $packed = array();
                foreach($data['fields'] as $k => $v) {
                    if ($v != '') $v = addslashes($v);                  
                    if (!is_array($v)) $packed[] = sprintf("`%s` = '%s'", addslashes($k), $v);
                }
                
                if (!count($packed)) {
                    return $this->innererr('no parameters to update in SQL query');
                }
                
                $query = sprintf("UPDATE `%s` SET %s WHERE %s", $data['tablename'], implode(', ', $packed), $where);
                $query = str_replace('!!!\'', '', str_replace('\'!!!', '', $query));

                return $this->innerok($query);               
            }            



        }
        
    }

?>
