This folder consists libraries that can be compiled to separate lambda layers. Try to keep the number of layers down due to the limitations inside Lambda.

full tutorial how to make function to compile your layers can be found at video

instructions file for layer compiler has to be in following format:

{
  "instructions": {
    "aws-php-include": [
      {
        "path": "/lambda/layer_compiler/aws_includes/class_awshelper.php",
        "destination": "includes/class_awshelper.php"
      },
	//...
    ]
  }
}

- "aws-php-includes" is the new / updateable file name
- "path" is where to get the file from
- "destination" is what folder to store it in lambda.
		in our tutorial case "destination": "/includes/bootstrap.php" will be uploaded to /opt/includes/bootstrap.php in lambda.

