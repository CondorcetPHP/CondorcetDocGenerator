<?php
declare(strict_types=1);

use HaydenPierce\ClassFinder\ClassFinder;
use Symfony\Component\Yaml\Yaml;

$start_time = microtime(true);

$pathDirectory = $argv[1];

if (substr($pathDirectory, -1) !== DIRECTORY_SEPARATOR) :
    $pathDirectory .= DIRECTORY_SEPARATOR;
endif;

$doc = Yaml::parseFile($pathDirectory.'doc.yaml');

// Header & Prefix
$header = $doc[0]['header'];
unset($doc[0]);

$undocumented_prefix = $doc[1]['undocumented_prefix'] . "\n";
unset($doc[1]);


// 
$index  = [];
$classList = [];
$FullClassList = ClassFinder::getClassesInNamespace('CondorcetPHP\Condorcet\\', ClassFinder::RECURSIVE_MODE);

foreach ($doc as &$entry) :
  if (isset($entry['publish']) && $entry['publish'] !== true) :
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

    if(!method_exists('CondorcetPHP\Condorcet\\'.$method['class'], $method['name'])) :
        print "The method does not exist >> ".$method['class']." >> ".$method['name']."\n";
    else :
        $method['ReflectionMethod'] = new ReflectionMethod ('CondorcetPHP\Condorcet\\'.$method['class'],$method['name']);

        checkEntry($method);
    endif;

    $index[$method['class']][$method['name']] = $method;
  endforeach;
endforeach;

$inDoc = 0;
$non_inDoc = 0;

foreach ($FullClassList as $FullClass) :
    $methods = (new ReflectionClass($FullClass))->getMethods(ReflectionMethod::IS_PUBLIC);
    $shortClass = str_replace('CondorcetPHP\Condorcet\\', '', $FullClass);

    foreach ($methods as $oneMethod) :
        if ( !isset($index[$shortClass][$oneMethod->name]) ) :
            $non_inDoc++;
        else :
            $inDoc++;
        endif;
    endforeach;
endforeach;

$privateAndUndocumentedList = [];

$total_methods = 0;
foreach ($FullClassList as $FullClass) :
    $methods = (new ReflectionClass($FullClass))->getMethods();
    $shortClass = str_replace('CondorcetPHP\Condorcet\\', '', $FullClass);

    foreach ($methods as $oneMethod) :
        if ( !isset($index[$shortClass][$oneMethod->name]) ) :
            $privateAndUndocumentedList[$shortClass][] = [  'FullClass' => $FullClass,
                                                            'shortClass' => $shortClass,
                                                            'name' => $oneMethod->name,
                                                            'static' => $oneMethod->isStatic(),
                                                            'visibility_public' => $oneMethod->isPublic(),
                                                            'visibility_protected' => $oneMethod->isProtected(),
                                                            'visibility_private' => $oneMethod->isPrivate(),
                                                            'ReflectionMethod' => $oneMethod,
                                                            'ReflectionClass' => $oneMethod->getDeclaringClass(),
                                                        ];
        endif;

        $total_methods++;
    endforeach;
endforeach;


print "Public methods in doc: ".$inDoc." / ".($inDoc + $non_inDoc)." | Total methods count: ".$total_methods." | Number of Class: ".count($FullClassList)."\n";

// Add Index
uksort($index,'strnatcmp');
$file_content = makeIndex($index, $header);

$file_content .= ".  \n.  \n.  \n";

uksort($privateAndUndocumentedList,'strnatcmp');
$file_content .= makeProfundis($privateAndUndocumentedList, $undocumented_prefix);


// Write file
file_put_contents($pathDirectory."\\README.md", $file_content);


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
  if (is_array($c)) : return '['.implode(',', $c).']'; endif;

  return (string) $c;
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

##### **".$key.":** *".((empty($value['nullable'])) ? $value['type'] : '?'.$value['type'])."*   
".((isset($value['text']))?$value['text']:"")."    
";
    endforeach;
endif;

    
    // Return Value

if (isset($entry['return'])) :


    $md .= "

### Return value:   

*(".$entry['return_type'].")* ".$entry['return']."
";
endif;

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
        $str .= " ";
        $str .= ($value['required'] === false && !$option) ? "[" : "";
        $str .= ($i > 0) ? ", " : "";
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
            "(".(empty($param) ? '' : '...').")";
}

function checkEntry(array $entry) : void
{
    $parameters = $entry['ReflectionMethod']->getParameters();

    $iec = isset($entry['input']) ? count($entry['input']) : 0;

    // Check different number of parameters
    if (count($parameters) !== $iec) :
        print 'Differents parameters count: '.$entry['class']."::".$entry['name']."\n";
    endif;

    // Check different name for parameters doc. vs technical
    foreach ($parameters as $p) :
        if (!in_array($p->name, array_keys($entry['input']), true)):
            print 'Input not in Reflection: '.$entry['class']."::".$entry['name']." => ".$p->name."  docInput:{".implode(',', array_keys($entry['input']))."}\n";
        endif;
    endforeach;

    // Check Parameters Orders
    if ($iec > 0) :
        $im = array_keys($entry['input']);
        reset($im);
        foreach ($parameters as $ri => $p) :
            if ($p->name !== current($im)):
                print 'Parameters Orders Warning: '.$entry['class']."::".$entry['name']." => ".$ri.":".$p->name."  docInput:{".implode(',', array_keys($entry['input']))."}\n";
            endif;

            next($im);
        endforeach;
    endif;

    // Check Type && Default Value
    if ($iec > 0) :
        $i = 0;
        foreach ($entry['input'] as $iName => $iParam) :
            $rfParam = $parameters[$i];
            $rfType = getReturnTypeAsString($rfParam->getType());
            $docType = ($iParam['nullable'] ?? false) ? '?'.$iParam['type'] : $iParam['type'];
            $docDefaultValue = !isset($iParam['default']) ? null : speakBool($iParam['default']);
            $rfDefaultValue = (!$rfParam->isDefaultValueAvailable()) ? null : speakBool($rfParam->getDefaultValue());

            // Check Type
            if ($rfType !== $docType && !(substr_count($iParam['type'],'mixed') === 1 && $rfType === null)) :
                print 'Different input type: '.$entry['class']."::".$entry['name'].">$".$iName." => Doc: ".$docType." / Reflection: ".$rfType."\n";
            endif;

            // Check default Value
            if ($rfDefaultValue !== $docDefaultValue) :
                print 'Different param default value: '.$entry['class']."::".$entry['name'].">$".$iName." => Doc: ".$docDefaultValue." / Reflection: ".$rfDefaultValue."\n";
            endif;
            $i++;
        endforeach;
    endif;


    // Check return type
    $reflection_return_type = getReturnTypeAsString($entry['ReflectionMethod']->getReturnType());

    $doc_return_type = $entry['return_type'] ?? null;

    if ($doc_return_type !== $reflection_return_type && !($reflection_return_type === null && $doc_return_type === '?mixed')) :
        print 'Different return type: '.$entry['class']."::".$entry['name']." => Doc: ".$doc_return_type." / Reflection: ".$reflection_return_type."\n";
    endif;
}

function getReturnTypeAsString (?\ReflectionType $rf_rt) : ?string
{
    if ( $rf_rt !== null ) :
        $allowsNull = $rf_rt->allowsNull();
        $rf_rt = $rf_rt->getName();
        $rf_rt = $allowsNull ? '?'.$rf_rt : $rf_rt;
    endif;

    return $rf_rt;
}


function makeIndex (array $index, $file_content ) : string
{
    foreach ($index as $class => $methods) :

        usort($methods,function (array $a, array $b) {
            if ($a['static'] === $b['static']) :
                return strnatcmp($a['name'],$b['name']);
            elseif ($a['static'] && !$b['static']) :
                return -1;
            else :
                return 1;
            endif;
        });

        $file_content .= "\n";
        $file_content .= '### CondorcetPHP\Condorcet\\'.$class." Class  \n\n";

        foreach ($methods as $oneMethod) :
            $url = str_replace("\\","_",$oneMethod['class']).' Class/'.$oneMethod['visibility'].' '.(($oneMethod['static'])?'static ':'') . str_replace("\\","_",$oneMethod['class']."--". $oneMethod['name']) . '.md' ;
            $url = str_replace(' ', '%20', $url);

            $file_content .= "* [".makeRepresentation($oneMethod, true)."](".$url.")";

            if ($oneMethod['ReflectionMethod']->hasReturnType()) :
                $file_content .= ' : '.getReturnTypeAsString($oneMethod['ReflectionMethod']->getReturnType());
            endif;


            $file_content .= "  \n";
        endforeach;

    endforeach;

    return $file_content;
}


function makeProfundis (array $index, $file_content) : string
{
    foreach ($index as $class => &$methods) :

        usort($methods,function (array $a, array $b) {
            if ($a['static'] === $b['static']) :
                if ( $a['visibility_public'] && !$b['visibility_public'] )  :
                    return -1;
                elseif ( !$a['visibility_public'] && $b['visibility_public'] ) :
                    return 1;
                else :
                    if ($a['visibility_protected'] && !$b['visibility_protected']) :
                        return -1;
                    elseif (!$a['visibility_protected'] && $b['visibility_protected']) :
                        return 1;
                    else :
                         return strnatcmp($a['name'],$b['name']);
                    endif;
                endif;
            elseif ($a['static'] && !$b['static']) :
                return -1;
            else :
                return 1;
            endif;
        });

        $ReflectionClass = new ReflectionClass('CondorcetPHP\Condorcet\\'.$class);

        $file_content .= "\n";
        $file_content .= '#### ';
        $file_content .= ($ReflectionClass->isAbstract()) ? 'Abstract ' : '';
        $file_content .= 'CondorcetPHP\Condorcet\\'.$class.' ';

        $file_content .= ($p = $ReflectionClass->getParentClass()) ? 'extends '.$p->name.' ' : '';

        $interfaces = implode(', ', $ReflectionClass->getInterfaceNames());
        $file_content .= (!empty($interfaces)) ? 'implements '.$interfaces : '';

        $file_content .= "  \n";



        foreach ($methods as $oneMethod) :
            $parameters = $oneMethod['ReflectionMethod']->getParameters();
            $parameters_string = '';

            $i = 0;
            foreach ($parameters as $oneP) :
                $parameters_string .= (++$i > 1) ? ', ' : '';

                if ($oneP->getType() !== null) :
                    $parameters_string .= getReturnTypeAsString($oneP->getType()) . ' ';
                endif;
                $parameters_string .= '$'.$oneP->name;

                if ($oneP->isDefaultValueAvailable()) :
                    $parameters_string .= ' = '.speakBool($oneP->getDefaultValue());
                endif;
            endforeach;

            $representation = ($oneMethod['visibility_public']) ? 'public ' : '';
            $representation .= ($oneMethod['visibility_protected']) ? 'protected ' : '';
            $representation .= ($oneMethod['visibility_private']) ? 'private ' : '';

            $representation .=  ($oneMethod['static']) ? 'static ' : '';
            $representation .=  $oneMethod['name'] . ' ('.$parameters_string.')';

            if ($oneMethod['ReflectionMethod']->hasReturnType()) :
                $representation .= ' : '.getReturnTypeAsString($oneMethod['ReflectionMethod']->getReturnType());
            endif;

            $file_content .= "* ".$representation."  \n";
        endforeach;

    endforeach;

    return $file_content;
}