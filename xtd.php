#!/usr/bin/php
<?php
#XTD:xmasek15

//error_reporting(0);

mb_internal_encoding('UTF-8');

$parameters = handle_parameters($argv);

$output_stream = STDOUT;
$input_stream = STDIN;
// open input and output files
open_file_streams($parameters, $input_stream, $output_stream);

if(array_key_exists("help", $parameters))
	print_help();

if(isset($parameters["header"]))
{
	fprintf($output_stream, "--%s\n\n", $parameters["header"]);
}

if(!($xml = simplexml_load_string(stream_get_contents($input_stream))))
{
	print_error("XML_LOAD", "XML is empty or root empty.", 0);
}

if(isset($parameters["isvalid"]))
{
	$xml_to_valid = simplexml_load_string(file_get_contents($parameters["isvalid"]));

	$relations = recursive_parent_child_relations($xml, array());
	$relations_to_valid = recursive_parent_child_relations($xml_to_valid, array());

	$tables = create_tables_with_attributes(xml_to_associative_array($xml), array());
	$tables_to_valid = create_tables_with_attributes(xml_to_associative_array($xml_to_valid), array());

	if(validate($tables, $tables_to_valid) == false)
		print_error("NOT_VALID");
}
else
{
	$relations = recursive_parent_child_relations($xml, array());

	$tables = create_tables_with_attributes(xml_to_associative_array($xml), array());
}


// if -g is set print the generated output of relations in xml format, else print out the ddl tables structure
fprintf($output_stream, "%s", (array_key_exists("g", $parameters) ? relationships_table($tables, $relations, $parameters) : createTables($tables, $relations, $parameters)));

//close the input and output files

fclose($input_stream);
fclose($output_stream);


/**
 * @param $tables array of tables of outputted xml
 * @param $tables_to_validate array of tables to validate
 *
 * @return bool true if is valid else false
 */
function validate($tables, $tables_to_validate)
{
	foreach($tables_to_validate as $key => $value)
	{
		if(count($tables_to_validate[$key]) > count($tables[$key]))
			return false;

		if(!isset($tables[$key]))
			return false;

		if(count($tables_to_validate[$key]["attributes"]) > count($tables[$key]["attributes"]))
			return false;

		if(count($tables_to_validate[$key]["keys"]) > count($tables[$key]["keys"]))
			return false;

		foreach($tables_to_validate[$key]["attributes"] as $attr_key => $attr_value)
		{
			if(!isset($tables[$key]["attributes"][$attr_key]))
				return false;
		}

		if($tables_to_validate[$key]["value"] != $tables[$key]["value"])
			return false;
	}

	return true;
}

/**
 * @param array    $parameters    passed to script
 * @param resource $input_stream  will be used to return a file to read from (default stdin)
 * @param resource $output_stream will be used to return a file to write to (default stdout)
 */
function open_file_streams($parameters, &$input_stream, &$output_stream)
{
	if(isset($parameters["output"]))
	{
		if(empty($parameters["output"]))
			print_error("PARAMS");
		if(($output_stream = fopen($parameters["output"], "w")) === false)
			print_error("OUTPUT");
	}

	if(isset($parameters["input"]))
	{
		if(empty($parameters["input"]))
			print_error("PARAMS");
		if(($input_stream = fopen($parameters["input"], "r")) === false)
			print_error("INPUT");
	}
}


/**
 * Will fill up attributes and data types of tables generated from xml objects
 *
 * @param  array $array  of converted xml objects
 * @param  array $tables of tables that will be filled
 * @param string $old    keyword
 *
 * @return mixed
 */
function create_tables_with_attributes($array, $tables, $old = "")
{
	foreach($array as $key => $value)
	{
		if(is_string($key)) // if key is string, so leads to object (single table)
		{
			$key = mb_strtolower($key, 'UTF-8');
			if(is_array($value)) // array of values means there is more sub attributes under it
			{
				if($key == "@attributes")
					set_attributes_data_types($tables[$old]["attributes"], $value);
				else
				{
					if(!array_key_exists($key, $tables))
						$tables[$key] = array("attributes" => array(), "keys" => array()); //create new empty substance of array

					$old = $key; // set odl key
				}
			}
			else if($key == "@value") // key is value so it represents value of the table
				set_value_type($tables[$old]["value"], $value, $old); // get data type of that value
		}

		if(is_array($value)) //if value is array (there are more sub tables) there is a need to call creating again...
			$tables = create_tables_with_attributes($value, $tables, $old);
	}

	return $tables;
}


/**
 * Sets correct data type for table value
 *
 * @param array $table where value data type will be set
 * @param       $value string used to recognize data type
 */
function set_value_type(&$table, $value)
{
	$tmp_value = trim($value);
	if(isset($table))
		$table = compare_data_type($table, recognize_data_type($value)); //if there already is set data type compare old and now to get the highest one
	else
		$table = (empty($tmp_value) ? NULL : recognize_data_type($value)); //if value (trimmed) is empty set it to NULL, else data type not set and there is a need to recognize it
}


/**
 * Sets correct data type for table attributes
 *
 * @param array $table      where data will be set
 * @param array $attributes that need to have data type set
 */
function set_attributes_data_types(&$table, $attributes)
{
	foreach($attributes as $key_attr => $attribute)
	{
		$key_attr = mb_strtolower($key_attr, 'UTF-8');

		if(isset($table[$key_attr]))
			$table[$key_attr] = compare_data_type($table[$key_attr], recognize_data_type($attribute)); //if there already is set data type compare old and now to get the highest one
		else
			$table[$key_attr] = recognize_data_type($attribute); // if data type not set, recognize it

		if($table[$key_attr] == "NTEXT") // setting an attributes data type requires a conversion of ntext type to nvarchar
			$table[$key_attr] = "NVARCHAR";
	}
}


/**
 * Function will get the relations between tables and return them in the array of relations.
 *
 * @param object $xml_object child object of the parent
 * @param array  $relations  to write the relations to
 * @param string $parent     name of the parent object
 *
 * @return mixed array of the relations between objects
 */
function recursive_parent_child_relations($xml_object, $relations, $parent = "")
{
	$parent = mb_strtolower($parent, 'UTF-8'); //lowering parent name

	$count_array = array(); // empty array will be used to count all of the occurrences of sub elements in table

	foreach($xml_object as $key => $sub_xml_object)
	{
		$key = mb_strtolower($key, 'UTF-8');

		if(!array_key_exists($key, $relations)) // if there is no evidence of key in relations, create an empty one
			$relations[$key] = array();

		if(!empty($parent)) //counting occurrences of same children under parental table
		{
			if(array_key_exists($key, $count_array))
				$count_array[$key]++;
			else
				$count_array[$key] = 1;
		}

		$relations = recursive_parent_child_relations($sub_xml_object, $relations, $key);
	}

	// will compare each each child occurrences and set the highest one to the relations array
	foreach($count_array as $child => $value)
	{
		$child = mb_strtolower($child, 'UTF-8'); //lowering keyword
		if(!empty($parent) && (!array_key_exists($child, $relations[$parent]) || $relations[$parent][$child] < $count_array[$child]))
			$relations[$parent][$child] = $count_array[$child];
	}

	return $relations;
}


/**
 * @param        $relations  array of relations (stored information about individual tables)
 * @param        $parameters array of program options passed in parameters
 * @param string $key        name of table
 * @param array  $values     used to check and prevent key conflicts
 *
 * @return string output that is representing DDL conversion from imputed xml
 */
function generate_attributes_output($relations, $parameters, $key, $values)
{
	$out = "";    // create empty output string (will be concatenated to final output)

	foreach($relations[$key] as $key_attr => $attribute)
	{
		$key_attr = mb_strtolower($key_attr, 'UTF-8');

		//if -b parameter is set, there will be only one column for same name
		if(array_key_exists("b", $parameters))
		{
			$key_attr .= "_id";

			if(array_key_exists($key_attr, $values["keys"]) || array_key_exists($key_attr, $values["attributes"]))    // check if key attribute is not already present in attributes or keys
				print_error("KEY_CONFLICT");

			$out .= ",\n\t" . $key_attr . " INT"; // generate output for column in table
		}
		else if(!array_key_exists("etc", $parameters) || $parameters["etc"] >= $attribute)    //else there will be etc (if set) or unlimited
		{    // iterate through attributes and make columns only for less than etc
			for($i = 1; $i <= $attribute; $i++)
			{
				if($attribute > 1)  //if there is more of the same name, concatenate numbers to them
					$atr_name = $key_attr . $i . "_id";
				else
					$atr_name = $key_attr . "_id";

				if(array_key_exists($atr_name, $values["keys"]) || array_key_exists($atr_name, $values["attributes"])) // check if key attribute is not already present in attributes or keys
					print_error("KEY_CONFLICT");

				$out .= ",\n\t" . $atr_name . " INT"; // generate output for new column in table
			}
		}
	}

	return $out;
}


/**
 * @param        $relations  array of relations (stored information about individual tables)
 * @param        $parameters array of program options passed in parameters
 * @param string $key        name of table
 * @param array  $values     used to check and prevent key conflicts
 *
 * @return string output that is representing DDL conversion from imputed xml
 */
function generate_table_members_output($relations, $parameters, $key, $values)
{
	$out = "";    // create empty output string (will be concatenated to final output)

	foreach($relations as $parent => $child)
	{
		$parent = mb_strtolower($parent, 'UTF-8'); //lowering parent name (for case insensitivity reasons)
		$parent_id = $parent . "_id"; // creating parent name concatenated with id tag

		foreach($relations[$parent] as $key_child => $attribute)
		{
			if(array_key_exists("etc", $parameters) && $parameters["etc"] < $attribute && $key_child == $key)
			{
				if(array_key_exists($parent_id, $values["keys"]) || array_key_exists($parent_id, $values["attributes"]))    // check if key attribute is not already present in attributes or keys
					print_error("KEY_CONFLICT");

				$out .= ",\n\t" . $parent_id . " INT";  // generating column from parent name
			}
		}
	}

	return $out;
}


/**
 * Function will generate output in DDL format from previously created and parsed tables structure.
 *
 * @param $tables     array of all tables to be generated output for
 * @param $relations  array of relations (stored information about individual tables)
 * @param $parameters array of program options passed in parameters
 *
 * @return string output that is representing DDL conversion from imputed xml
 */
function createTables($tables, $relations, $parameters)
{
	$out = "";    // create empty output string (will be concatenated to final output)

	foreach($tables as $key => $value)
	{
		$key = mb_strtolower($key, 'UTF-8'); //lower key (program is case insensitive)

		//set and generate the primary key output
		$primary_key = "prk_" . $key . "_id";
		$out .= "CREATE TABLE " . $key . " (\n\t" . $primary_key . " INT PRIMARY KEY";

		if(array_key_exists($primary_key, $value["keys"]) || array_key_exists($primary_key, $value["attributes"]))    // check if key attribute is not already present in attributes or keys
			print_error("KEY_CONFLICT");

		// generate attributes output
		$out .= generate_attributes_output($relations, $parameters, $key, $value);

		$out .= generate_table_members_output($relations, $parameters, $key, $value);

		//if -a is set, no tables from attributes will be generated
		if(array_key_exists("a", $parameters))
		{
			if(isset($value["value"])) // generate value with its data type
				$out .= ",\n\t" . "value " . $value["value"];
		}
		else
		{
			// generate attribute members of table
			foreach($value["attributes"] as $key_attr => $attribute)
			{
				if($key_attr != "value")
					$out .= ",\n\t" . $key_attr . " " . $attribute;
				else
					$value["value"] = compare_data_type($value["value"], $attribute);
			}

			if(isset($value["value"])) // generate value with its data type
				$out .= ",\n\t" . "value " . $value["value"];
		}

		$out .= "\n);\n\n";
	}

	return $out;
}


/**
 * @param $relations_array array of that will be filled with another one to many or many to one transitivities
 */
function relationships_one_to_many(&$relations_array)
{
	do
	{
		$change = false; // no change has occurred yet
		foreach($relations_array as $a => $value)
		{
			foreach($relations_array[$a] as $c => $value_child) // iterate through every a->c transitivity
			{
				if($relations_array[$a][$c] == "1:N" || $relations_array[$a][$c] == "N:1") //there is an 1:N or N:1 a->c transitivity
				{
					foreach($relations_array[$c] as $b => $value) // iterate through every c->b transitivity
					{
						if($relations_array[$c][$b] == $relations_array[$a][$c] && !isset($relations_array[$a][$b])) // if it is same case as an a->c transitivity and a->b is not set yet
						{
							$change = true; //change has been made
							$relations_array[$a][$b] = $relations_array[$c][$b]; // set it to same as c->b (a->c)
						}
					}
				}
			}
		}
	} while($change);
}

/**
 * @param $relations_array array of that will be filled with another many to many transitivities
 */
function relationships_many_to_many(&$relations_array)
{
	do
	{
		$change = false; // no change has occurred yet
		foreach($relations_array as $a => $value)
		{
			foreach($relations_array[$a] as $c => $value_child) // iterate through every a->c transitivity
			{
				foreach($relations_array[$c] as $b => $value) // iterate through every c->b transitivity
				{
					if(!isset($relations_array[$a][$b])) // no a->b transitivity is set
					{
						$change = true; //change has been made
						$relations_array[$a][$b] = $relations_array[$b][$a] = "N:M"; // set a->b and c->a to N:M transitivity
					}
				}
			}
		}
	} while($change);

}

/**
 * @param $tables          array of tables
 * @param $relations_array array of that will be filled with basic transitivities
 */
function relationships_initialisation($tables, &$relations_array)
{
	foreach($tables as $key => $value)            // transitivity for 1:1 (a = b)
	{
		$relations_array[$key][$key] = "1:1";
	}

	foreach($relations_array as $key_parent => $value)   // transitivity for N:M (a!=b and a->b and b->a)
	{
		foreach($relations_array[$key_parent] as $key_child => $value_child)
		{
			$relations_array[$key_parent][$key_child] = recognize_relationship($relations_array, $key_parent, $key_child);
		}
	}

	foreach($relations_array as $key_parent => $value)
	{
		foreach($relations_array[$key_parent] as $key_child => $value_child)
		{
			if($relations_array[$key_parent][$key_child] == "1:N" || $relations_array[$key_parent][$key_child] == "N:1") // if there is 1:N or N:1 there is also backward swapped transitivity
				$relations_array[$key_child][$key_parent] = ($relations_array[$key_parent][$key_child] == "1:N" ? "N:1" : "1:N");

			if($relations_array[$key_parent][$key_child] == "N:M") //if transitivity exist in one direction there is also transitivity backwards
				$relations_array[$key_child][$key_parent] = "N:M";
		}
	}
}


/**
 * Function generates and returns relations between tables in xml formatted output string
 *
 * @param $tables     array of tables
 * @param $relations  array of relations between parent and children
 * @param $parameters array of parameters passed to script
 *
 * @return string generated output in xml format representing relations between tables
 */
function relationships_table($tables, $relations, $parameters)
{
	$relations_array = fill_relationship_array($tables, $relations, $parameters);

	// initialize basic relations
	relationships_initialisation($tables, $relations_array);

	// complete one to many
	relationships_one_to_many($relations_array);

	//complete many to many
	relationships_many_to_many($relations_array);

	return generate_relations_xml_out($relations_array);
}


/**
 * Generates the XML like format output of relationships between tables.
 *
 * @param $relations_array array of relationships between tables
 *
 * @return string output that will be printed in xml format
 */
function generate_relations_xml_out($relations_array)
{
	$out = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"; // print XML header tag to output file
	$out .= "<tables>\n";

	foreach($relations_array as $key => $value)
	{
		$out .= "\t<table name=\"" . $key . "\">\n";
		foreach($value as $key_child => $relation) //generate relations in tags named as table children
		{
			$out .= "\t\t<relation to=\"" . $key_child . "\" relation_type=\"" . $relation . "\" />\n";
		}
		$out .= "\t\t</table>\n";
	}
	$out .= "</tables>\n";

	return $out;
}


/**
 * Fill array of relationship between tables.
 *
 * @param $tables     array of tables
 * @param $relations  array of relations between parent and children
 * @param $parameters array of parameters passed to script
 *
 * @return array filled relationship array
 */
function fill_relationship_array(&$tables, &$relations, $parameters)
{
	$relations_array = array();
	foreach($tables as $key => $value)
	{
		foreach($relations[$key] as $key_attr => $attribute)
		{
			if((!array_key_exists("etc", $parameters) && !array_key_exists("b", $parameters)) || (array_key_exists("etc", $parameters) && $parameters["etc"] >= $attribute) || array_key_exists("b", $parameters))
				$relations_array[$key][$key_attr] = 1;
		}

		foreach($relations as $parent => $child)
		{
			foreach($relations[$parent] as $key_child => $attribute)
			{
				if(array_key_exists("etc", $parameters) && ($parameters["etc"] < $attribute) && $key_child == $key)
					$relations_array[$key][$parent] = 1;
			}
		}
	}

	return $relations_array;
}


/**
 * Function to recognize relationships between two tables
 *
 * @param array  $relations_array of relation between tables
 * @param string $parent          first table
 * @param string $child           second table
 *
 * @return string symbolizing tables relationship
 */
function recognize_relationship($relations_array, $parent, $child)
{
	$out = "";    // create empty output string (will be concatenated to final output)
	if($parent == $child) // if child and parent are same
		return "1:1";

	$out = isset($relations_array[$parent][$child]) ? $out . "N" : $out . "1"; //relation parent to children

	$out = isset($relations_array[$child][$parent]) ? $out . ":N" : $out . ":1"; //relation child to parents

	return ($out == "N:N" ? "N:M" : $out); //converting "N:N" relation to "N:M"
}


/**
 * Function compares $old and $new data types represented as strings and returns valid (more complex) one.
 *
 * @param string $old Data type
 * @param string $new Data type
 *
 * @return string Representing more complex data type
 */
function compare_data_type($old, $new)
{
	if($old == $new) //if same return new (it does not mather)
		return $new;

	// priority of  data type from highest to lowest
	if($new == "NTEXT")
		return "NTEXT";
	else if($old != "NTEXT" && $new == "NVARCHAR")
		return "NVARCHAR";
	else if($old != "NTEXT" && $old != "NVARCHAR" && $new == "FLOAT")
		return "FLOAT";
	else if($old != "NTEXT" && $old != "NVARCHAR" && $old != "FLOAT" && $new == "INT")
		return "INT";
	else if($old != "NTEXT" && $old != "NVARCHAR" && $old != "FLOAT" && $old != "INT" && $new == "BIT")
		return "BIT";
	else
		return $old; // if neither of them was considered as new data type, return old
}


/**
 * Convert Simple xml object to array for easy parsing.
 *
 * @param $xml_object object that will be converted to array
 *
 * @return array of xml_objects
 */
function xml_to_associative_array($xml_object)
{
	$objects_array = array(); // create empty array where structure of objects will be converted

	foreach($xml_object->attributes() as $attr_key => $value) // set attributes id and values
	{
		$objects_array['@attributes'][$attr_key] = (string)$value;
	}

	foreach($xml_object as $index => $child) // recursively call function on all of the children (xml sub objects)
	{
		$objects_array[$index][] = xml_to_associative_array($child);
	}

	if($xml_object->count() == 0) // if its the last object in tree and there is no other children
		$objects_array['@value'] = $xml_object->__toString(); //convert value to string and store it under value keyword

	return $objects_array;
}


/**
 * Function to recognize data type of the string passed to validate.
 *
 * @param string $string - to validate type from
 *
 * @return string "INT" or "FLOAT" for numeric type
 *                "BIT" for boolean or empty $string
 *                "NTEXT" for text like type
 */
function recognize_data_type($string)
{
	$string = mb_strtolower(trim($string), 'UTF-8');

	// if the data type is empty string or any of bool representations set it as bool
	if(empty($string) || is_bool(filter_var($string, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)))
		return "BIT";
	if(is_int(filter_var($string, FILTER_VALIDATE_INT))) // else if int set as int
		return "INT";
	if(is_float(filter_var($string, FILTER_VALIDATE_FLOAT))) // or float
		return "FLOAT";

	return "NTEXT"; // if neither of them set it to NTEXT (doesn't mather if it is attribute or not, it will be decided later)
}


/**
 * Parse the parameters of script using getopt function.
 *
 * @param $argv array of string parameters passed to script
 *
 * @return array of options that script will use
 */
function handle_parameters($argv)
{
	$short_opts = "a";      // Do not generate cols
	$short_opts .= "b";     // Group elements
	$short_opts .= "g";     // Relations option

	$long_opts = array("help",  // Prints help and exits program
		"input::",          // Optional input file
		"output::",         // Optional output file
		"header::",         // Optional header text
		"etc::",            // Optional max columns
		"isvalid::",        // extension (validating of XML)
	);

	unset($argv[0]); // Unset script name in arguments

	$options = getopt($short_opts, $long_opts); // call getopt to parse args

	foreach($argv as $arg)
		if(!preg_match('/^(--((isvalid)|(input)|(output)|(header)|(etc))=.+)|(^-[abg]+)|(^--help+)/', $arg)) // regular expression to validate the correctness of the parameters
			print_error("BAD_PARAM", $arg . " was not recognized as parameter.");

	if(isset($options["etc"]))
	{
		if(isset($options["b"]))                                // --etc and -b can not be set together
			print_error("PARAMS", "error: -b and --etc= are set together");

		$options["etc"] = intval($options["etc"]);
		if(!is_int($options["etc"]))    // after --etc= there must be an int number
			print_error("PARAMS", "etc value is not integer.");
	}

	if(isset($options["help"]))
	{
		if(!preg_match('/^--help+/', implode(" ", $argv)))
			print_help(1);
		else
			print_help();
	}

	if(isset($options["input"]))
	{
		if(!file_exists($input = $options["input"])) //checking if input file exists
		{
			print_error("INPUT", $options["input"] . " can not be opened.");
		}
	}

	if(isset($options["output"]))
	{
		if($options["output"] === $options["input"]) // input and output can not be the same file
			print_error("OUTPUT", $options["output"] . " file is same for input and output.");
	}

	return $options;
}


/**
 * Prints help to the STDOUT and exits with error code.
 *
 * @param int $err_code to exit with (default 0)
 */
function print_help($err_code = 0)
{
	printf("
	Help:\n
	--help - this help will be printed
	--input=FILE_NAME  - input xml file (if not provided stdin is used)
	--output=FILE_NAME - output file
	--header='HEADER'  - this header will be written to the beginning of the output file
	--etc=N            - max number of columns generated from same named sub elements
	-a - columns from attributes in imputed xml will not be generated
	-b - if element will have more sub elements of same name, only one will be generated
	   - cannot be combined with --etc option\n\n");
	exit($err_code);
}


/**
 * Function providing error messages and returning error codes.
 *
 * @param string $err  what error has occurred
 * @param string $msg  to be shown with occurred error
 * @param int    $code error code to return (used with runtime and other errors)
 */
function print_error($err = "", $msg = "", $code = -1)
{
	switch($err)
	{
		case "BAD_PARAM":
			fprintf(STDERR, "Unknown parameter.");
			$code = 1;
			break;
		case "PARAMS":
			fprintf(STDERR, "Wrong script parameters combination.");
			$code = 1;
			break;
		case "INPUT":
			fprintf(STDERR, "Input file error.");
			$code = 2;
			break;
		case "OUTPUT":
			fprintf(STDERR, "Output file error.");
			$code = 3;
			break;
		case "KEY_CONFLICT":
			fprintf(STDERR, "KEY_CONFLICT error.");
			$code = 90;
			break;
		case "NOT_VALID":
			fprintf(STDERR, "XML not valid.");
			$code = 91;
			break;
		case "RUN":
			fprintf(STDERR, "RUNTIME error.");
			if($code === -1)
				$code = 99;
			break;
		default:
			fprintf(STDERR, "Other error.");
			if($code === -1)
				$code = 127;
	}

	if($msg === "")
		fprintf(STDERR, "\n");
	else
		fprintf(STDERR, "\n\t%s\n", $msg);

	exit($code);
}

?>
