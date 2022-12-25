<?php

	/*
		Bootstrap File for AWS Lambda
	*/

	if (!class_exists('awshelper'))
	{
		class awshelper 
		{
			public $version;

			function __construct($data)
			{
				$this->version = 1;
			}

		}
	}

?>
