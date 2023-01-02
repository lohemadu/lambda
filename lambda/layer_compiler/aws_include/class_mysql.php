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
			        //connection not established... exit
			        return $helper->doError('connection to database is not established: %s', $data['connection']);
			    } else {
			        $conn = $helper->metadata['connections'][$data['connection']]['object'];
			    }
			    
			    if (!$res = $conn->query($data['query'])) {
			        return $helper->doError('mysql error: ' . $conn->error . 'when running a query: <br><br>' . $data['query']);
			    }
			    
                //no records return
                if (!mysqli_num_rows($res)) {
                    if (isset($data['no-rows-error']) && $data['no-rows-error'] == false) {
                        return $helper->doError('query returned zero results');
                    } else {
                        return $helper->doOk([]);
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
			
		}
	}

?>
