<?php
declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

$start_time = microtime(true);

$pathDirectory = $argv[1];

if (substr($pathDirectory, -1) !== DIRECTORY_SEPARATOR) :
    $pathDirectory .= DIRECTORY_SEPARATOR;
endif;

$doc = Yaml::parseFile($pathDirectory.'doc.yaml');
$index  = [];

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

    $index[$method['class']][$method['name']] = $method;
  endforeach;
endforeach;

uksort($index,'strnatcmp');
makeIndex($index);


echo 'YAH ! <br>' . (microtime(true) - $start_time) .'s';

function makeFilename (array $method) : string
{
  return  $method['visibility'].
          (($method['static']) ? " static " : " "). 
          str_replace("\\", "_", $method['class'])."--".$method['name'].
          ".md";
}

function speakBool ($c) : string
{
  if ($c === true || $c === 'true') : return 'true'; endif;
  if ($c === false || $c === 'false') : return 'false'; endif;
  if ($c === null || $c === 'null') : return 'null'; endif;

  return $c;
}


function cleverRelated (string $name) : string
{
  $infos = explode('::', $name);
  $infos[0] = str_replace('static ', '', $infos[0]);

  $url = '../'.$infos[0].' Class/public '.str_replace('::', '--', $name) . '.md' ;
  $url = str_replace(' ', '%20', $url);

  return "[".$name."](".$url.")";
}


function createMarkdownContent (array $entry) : string
{
    // Header

    $md =
"## ".
$entry['visibility'].
(($entry['static']) ? " static " : " "). 
$entry['class']."::".$entry['name'].     "

### Description    

".makeRepresentation($entry)."

".$entry['description']."    ";

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

        foreach ($entry['related'] as $value) :

            if ($value === $entry['class'].'::'.$entry['name']) : continue; endif;

            $md .= "* ".cleverRelated($value)."    
";
        endforeach;

    endif;

    if(!empty($entry['examples'])) :

        $md .=
"
---------------------------------------

### Examples and explanation

";

        foreach ($entry['examples'] as $key => $value) :
            $md .= "* **[".$key."](".$value.")**    
";
        endforeach;

    endif;

    return $md;

}

function makeRepresentation (array $entry, bool $link = false) : string
{
    if (!$link) :
        return computeRepresentationAsPHP($entry['static'], $entry['visibility'], $entry['class'],$entry['name'],(isset($entry['input'])) ? $entry['input'] : null, (isset($entry['return_type'])) ? $entry['return_type'] : null);
    else :
        return computeRepresentationAsForIndex($entry['static'], $entry['visibility'], $entry['class'],$entry['name'],(isset($entry['input'])) ? $entry['input'] : null, (isset($entry['return_type'])) ? $entry['return_type'] : null);
    endif;
}

function computeRepresentationAsPHP (bool $static, string $public, string $class, string $method, ?array $param, ?string $return_type) : string
{

    $option = false;
    $str = '(';
    $i = 0;

if (is_array($param)) :
    foreach ($param as $key => $value) :
        $str .= ($value['required'] === false && !$option) ? " [" : "";
        $str .= ($i > 0) ? "," : "";
        $str .= " ";
        $str .= (isset($value['nullable']) && $value['nullable'] && $value['type'] !== "mixed") ? "?" : "";
        $str .= $value['type'];
        $str .= " ";
        $str .= $key;
        $str .= (isset($value['default'])) ? " = ".speakBool($value['default']) : "";

        ($value['required'] === false && !$option) ? $option = true : null;
        $i++;
    endforeach;
endif;

    if ($option) :
        $str .= "]";
    endif;

    $str .= " )";

    return "```php
".$public." ".(($static)?"static ":'$').$class.(($static)?"::":' -> ').$method." ".$str. ( ($return_type !== null) ? " : ".$return_type : "" )."
```";
}

function computeRepresentationAsForIndex (bool $static, string $public, string $class, string $method, ?array $param, ?string $return_type) : string
{
    return  $public." ".
            (($static)?"static ":'').
            $class.
            (($static)?"::":'->').
            $method.
            "()";
}


function makeIndex (array $index) : void
{
    global $pathDirectory;

    $file_content = "# Public Methods Index _(Not yet exhaustive, not yet....)*_\n";
    $file_content .=  "_Not including technical public methods which ones are used for very advanced use between components (typically if you extend Coondorcet or build your own modules)._    \n\n";

    $file_content .=  "_*: I try to update and complete the documentation. See also [the manual](https://github.com/julien-boudry/Condorcet/wiki), [the tests](../Tests) also produce many examples. And create issues for questions or fixing documentation!_    \n\n";

    foreach ($index as $class => &$methods) :

        usort($methods,function (array $a, array $b) {
            if ($a['static'] === $b['static']) :
                return strnatcmp($a['name'],$b['name']);
            elseif ($a['static'] && !$b['static']) :
                return -1;
            else :
                return 1;
            endif;
        });

        $file_content .= '## CondorcetPHP\Condorcet\\'.$class." Class  \n\n";

        foreach ($methods as $oneMethod) :
            $url = $oneMethod['class'].' Class/'.$oneMethod['visibility'].' '.(($oneMethod['static'])?'static ':'') . $oneMethod['class']."--". $oneMethod['name'] . '.md' ;
            $url = str_replace(' ', '%20', $url);

            $file_content .= "* [".makeRepresentation($oneMethod, true)."](".$url.")  \n";
        endforeach;

    endforeach;


    file_put_contents($pathDirectory."\\README.md", $file_content);
}