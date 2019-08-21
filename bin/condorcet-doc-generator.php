<?php
declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

$start_time = microtime(true);

$pathDirectory = $argv[1];

if (substr($pathDirectory, -1) !== DIRECTORY_SEPARATOR) :
    $pathDirectory .= DIRECTORY_SEPARATOR;
endif;

$doc = Yaml::parseFile($pathDirectory.'doc.yaml');

foreach ($doc as $entry) :
  if (!isset($entry['publish']) || $entry['publish'] !== true) :
    continue;
  endif;

  if (!is_array($entry['class'])) :
    $entry['class'] = array($entry['class']);
  endif;

  foreach ($entry['class'] as $class) :
  	$method = $entry ;
    $method['class'] = $class;

  	$path = $pathDirectory . str_replace("\\", "_", $method['class']) . " Class/";

    if (!is_dir($path)) :
        mkdir($path);
    endif;

  	file_put_contents($path.makeFilename($method), createMarkdownContent($method));
  endforeach;
endforeach;

echo 'YAH ! <br>' . (microtime(true) - $start_time) .'s';

function makeFilename ($method) {
  return  $method['visibility'].
          (($method['static']) ? " static " : " "). 
          str_replace("\\", "_", $method['class'])."--".$method['name'].
          ".md";
}

function speakBool ($c)
{
  if ($c === true || $c === 'true') : return 'true'; endif;
  if ($c === false || $c === 'false') : return 'false'; endif;
  if ($c === null || $c === 'null') : return 'null'; endif;

  return $c;
}

function computeCleverSpec ($static, $public, $class, $method, $param, $return_type) {

	$option = false;
	$str = '(';
	$i = 0;

if (is_array($param)) :	foreach ($param as $key => $value) :
		$str .= ($value['required'] === false && !$option) ? " [" : "";
		$str .= ($i > 0) ? "," : "";
		$str .= " ";
        $str .= (isset($value['nullable']) && $value['nullable'] && $value['type'] !== "mixed") ? "?" : "";
		$str .= $value['type'];
		$str .= " ";
		$str .= $key;
		$str .= (isset($value['default'])) ? " = ".speakBool($value['default']) : "";

		if ($value['required'] === false && !$option) { $option = true; }
		$i++;
	endforeach;
endif;

	if ($option) {
		$str .= "]";
	}

	$str .= " )";


	return "```php
".$public." ".(($static)?"static ":'$').$class.(($static)?"::":' -> ').$method." ".$str. ( ($return_type !== null) ? " : ".$return_type : "" )."
```";
}

function cleverRelated ($name)
{
  $infos = explode('::', $name);
  $infos[0] = str_replace('static ', '', $infos[0]);

  $url = '../'.$infos[0].' Class/public '.str_replace('::', '--', $name) . '.md' ;
  $url = str_replace(' ', '%20', $url);

  return "[".$name."](".$url.")";
}


function createMarkdownContent (array $entry)
{

	// Header

	$md =
"## ".
$entry['visibility'].
(($entry['static']) ? " static " : " "). 
$entry['class']."::".$entry['name'].     "

### Description    

".computeCleverSpec($entry['static'], $entry['visibility'], $entry['class'],$entry['name'],(isset($entry['input'])) ? $entry['input'] : null, (isset($entry['return_type'])) ? $entry['return_type'] : null)."

".$entry['description']."    
";

	// Input


if (isset($entry['input'])) :
	foreach ($entry['input'] as $key => $value ) :
$md .= "

##### **".$key.":** *".$value['type']."*   
".((isset($value['text']))?$value['text']:"")."    

";
	endforeach;
endif;

	
	// Return Value

	$md .= "

### Return value:   

".$entry['return']."

";

	// Related methods

	if(!empty($entry['related'])) :

		$md .=
"
---------------------------------------

### Related method(s)      

";

		foreach ($entry['related'] as $value) {

      if ($value === $entry['class'].'::'.$entry['name']) : continue; endif;

$md .= "* ".cleverRelated($value)."    
";
		}

	endif;

	if(!empty($entry['examples'])) :

		$md .=
"
---------------------------------------

### Examples and explanation

";

	foreach ($entry['examples'] as $key => $value) {
$md .= "* **[".$key."](".$value.")**    
";
	}

	endif;

	return $md;

}
