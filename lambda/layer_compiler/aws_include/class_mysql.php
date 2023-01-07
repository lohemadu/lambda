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
        }
        
    }

?>
