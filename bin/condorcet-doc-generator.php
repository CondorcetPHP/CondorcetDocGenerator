<?php
declare(strict_types=1);

use CondorcetPHP\Condorcet\CondorcetDocAttributes\{Description, Examples, FunctionReturn, PublicAPI, Related};
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
$FullClassList = \array_filter($FullClassList,function (string $value) { return (strpos($value, 'Condorcet\Test') === FALSE); });

foreach ($doc as &$entry) :

  if (!is_array($entry['class'])) :
    $entry['class'] = [$entry['class']];
  endif;

  foreach ($entry['class'] as $class) :
    $method = $entry ;
    $method['class'] = $class;
    if(!method_exists('CondorcetPHP\Condorcet\\'.$method['class'], $method['name'])) :
        print "The method does not exist >> ".$method['class']." >> ".$method['name']."\n";
    else :
        $method['ReflectionMethod'] = new ReflectionMethod ('CondorcetPHP\Condorcet\\'.$method['class'],$method['name']);

        checkEntry($method);
    endif;
    $method['return_type'] = getTypeAsString($method['ReflectionMethod']->getReturnType());

    $path = $pathDirectory . str_replace("\\", "_", $method['class']) . " Class/";

    if (!is_dir($path)) :
        mkdir($path);
    endif;

    file_put_contents($path.makeFilename($method), createMarkdownContent($method));

    $index[$method['class']][$method['name']] = $method;
  endforeach;
endforeach;

$inDoc = 0;
$non_inDoc = 0;

foreach ($FullClassList as $FullClass) :
    $methods = (new ReflectionClass($FullClass))->getMethods(ReflectionMethod::IS_PUBLIC);
    $shortClass = str_replace('CondorcetPHP\Condorcet\\', '', $FullClass);

    foreach ($methods as $oneMethod) :
        if ( !isset($index[$shortClass][$oneMethod->name]) && !$oneMethod->isInternal()) :
            $non_inDoc++;

            if (!empty($oneMethod->getAttributes(PublicAPI::class)) && $oneMethod->getName() !== 'getObjectVersion') :
                var_dump('Method Has Public API attribute, but not in doc.yaml file: '.$oneMethod->getDeclaringClass()->getName().'->'.$oneMethod->getName());
            endif;

        else :
            $inDoc++;

            if (empty($oneMethod->getAttributes(PublicAPI::class)) && $oneMethod->getDeclaringClass()->getNamespaceName() !== "") :
                var_dump('Method not has API attribute, but is in doc.yaml file: '.$oneMethod->getDeclaringClass()->getName().'->'.$oneMethod->getName());
            endif;

            if ( empty($oneMethod->getAttributes(Description::class)) && $oneMethod->getDeclaringClass()->getNamespaceName() !== "") :
                var_dump('Description Attribute is empty: '.$oneMethod->getDeclaringClass()->getName().'->'.$oneMethod->getName());
            endif;
        endif;
    endforeach;
endforeach;

$full_methods_list = [];

$total_methods = 0;
$total_nonInternal_methods = 0;

foreach ($FullClassList as $FullClass) :
    $methods = (new ReflectionClass($FullClass))->getMethods();
    $shortClass = str_replace('CondorcetPHP\Condorcet\\', '', $FullClass);

    foreach ($methods as $oneMethod) :
        if ( true /*!isset($index[$shortClass][$oneMethod->name])*/ ) :
            $full_methods_list[$shortClass][] = [   'FullClass' => $FullClass,
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

        if (!$oneMethod->isInternal()) :
            $total_nonInternal_methods++;
        endif;

    endforeach;
endforeach;


print "Public methods in doc: ".$inDoc." / ".($inDoc + $non_inDoc)." | Total non-internal methods count: ".$total_nonInternal_methods." | Number of Class: ".count($FullClassList)." | Number of Methods including internals: ".$total_methods."\n";

// Add Index
uksort($index,'strnatcmp');
$file_content = makeIndex($index, $header);

$file_content .= ".  \n.  \n.  \n";

uksort($full_methods_list,'strnatcmp');
$file_content .= makeProfundis($full_methods_list, $undocumented_prefix);


// Write file
file_put_contents($pathDirectory."\\README.md", $file_content);


echo 'YAH ! <br>' . (microtime(true) - $start_time) .'s';

function makeFilename (array $method) : string
{
    return  getVisibility($method['ReflectionMethod']).
            ($method['ReflectionMethod']->isStatic() ? " static " : " ").
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
getVisibility($entry['ReflectionMethod']).
($entry['ReflectionMethod']->isStatic() ? " static " : " "). 
$entry['class']."::".$entry['name'].     "

### Description    

".makeRepresentation($entry)."

".$entry['ReflectionMethod']->getAttributes(Description::class)[0]->getArguments()[0]."\n    ";

    // Input


if (!empty($entry['ReflectionMethod']->getParameters())) :
    foreach ($entry['ReflectionMethod']->getParameters() as $key => $value ) :

$md .= "

##### **".$value->getName().":** *".getTypeAsString($value->getType())."*   
".((isset($entry['input'][$value->getName()]['text'])) ? $entry['input'][$value->getName()]['text'] : "")."    
";
    endforeach;
endif;

    
    // Return Value

if (!empty($entry['ReflectionMethod']->getAttributes(FunctionReturn::class))) :


    $md .= "

### Return value:   

*(".$entry['return_type'].")* ".$entry['ReflectionMethod']->getAttributes(FunctionReturn::class)[0]->getArguments()[0]."\n\n";
endif;

    // Related methods

    if(!empty($entry['ReflectionMethod']->getAttributes(Related::class))) :

        $md .=
"
---------------------------------------

### Related method(s)      

";

        foreach ($entry['ReflectionMethod']->getAttributes(Related::class)[0]->getArguments() as $value) :

            if ($value === $entry['class'].'::'.$entry['name']) : continue; endif;

            $md .= "* ".cleverRelated($value)."    
";
        endforeach;

    endif;

    if(!empty($entry['ReflectionMethod']->getAttributes(Examples::class))) :

        $md .=
"
---------------------------------------

### Examples and explanation

";

        foreach ($entry['ReflectionMethod']->getAttributes(Examples::class)[0]->getArguments() as $value) :
            $value = explode('||',$value);
            
            $md .= "* **[".$value[0]."](".$value[1].")**    
";
        endforeach;

    endif;

    return $md;

}

function getVisibility (ReflectionMethod $rf) : string
{
    if ($rf->isPublic()) :
        return 'public';
    elseif ($rf->isProtected()) :
        return 'protected';
    elseif ($rf->isPrivate()) :
        return 'private';
    else :
        return '??';
    endif;
}

function makeRepresentation (array $entry, bool $link = false) : string
{
    if (!$link) :
        return computeRepresentationAsPHP($entry['ReflectionMethod']->isStatic(), getVisibility($entry['ReflectionMethod']), $entry['class'],$entry['name'],$entry['ReflectionMethod']->getParameters(), (isset($entry['return_type'])) ? $entry['return_type'] : null);
    else :
        return computeRepresentationAsForIndex($entry['ReflectionMethod']->isStatic(), getVisibility($entry['ReflectionMethod']), $entry['class'],$entry['name'],$entry['ReflectionMethod']->getParameters(), (isset($entry['return_type'])) ? $entry['return_type'] : null);
    endif;
}

function computeRepresentationAsPHP (bool $static, string $public, string $class, string $method, array $param, ?string $return_type) : string
{

    $option = false;
    $str = '(';
    $i = 0;


if (!empty($param)) :
    foreach ($param as $value) :
        $str .= " ";
        $str .= ($value->isOptional() && !$option) ? "[" : "";
        $str .= ($i > 0) ? ", " : "";
        $str .= getTypeAsString($value->getType());
        $str .= " ";
        $str .= $value->getName();
        $str .= ($value->isDefaultValueAvailable()) ? " = ".speakBool($value->getDefaultValue()) : "";

        ($value->isOptional() && !$option) ? $option = true : null;
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

function computeRepresentationAsForIndex (bool $static, string $public, string $class, string $method, array $param, ?string $return_type) : string
{
    return  $public." ".
            (($static)?"static ":'').
            $class.
            (($static)?"::":'->').
            $method.
            " (".(empty($param) ? '' : '...').")";
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
}

function getTypeAsString (?\ReflectionType $rf_rt) : ?string
{
    if ( $rf_rt !== null ) :
        return (string) $rf_rt;
    endif;

    return $rf_rt;
}


function makeIndex (array $index, string $file_content ) : string
{
    foreach ($index as $class => $methods) :

        usort($methods,function (array $a, array $b) {
            if ($a['ReflectionMethod']->isStatic() === $b['ReflectionMethod']->isStatic()) :
                return strnatcmp($a['name'],$b['name']);
            elseif ($a['ReflectionMethod']->isStatic() && !$b['ReflectionMethod']->isStatic()) :
                return -1;
            else :
                return 1;
            endif;
        });

        $file_content .= "\n";
        $file_content .= '### CondorcetPHP\Condorcet\\'.$class." Class  \n\n";

        foreach ($methods as $oneMethod) :
            $url = str_replace("\\","_",$oneMethod['class']).' Class/'.getVisibility($oneMethod['ReflectionMethod']).' '.(($oneMethod['ReflectionMethod']->isStatic())?'static ':'') . str_replace("\\","_",$oneMethod['class']."--". $oneMethod['name']) . '.md' ;
            $url = str_replace(' ', '%20', $url);

            $file_content .= "* [".makeRepresentation($oneMethod, true)."](".$url.")";

            if (isset($oneMethod['ReflectionMethod']) && $oneMethod['ReflectionMethod']->hasReturnType()) :
                $file_content .= ' : '.getTypeAsString($oneMethod['ReflectionMethod']->getReturnType());
            endif;


            $file_content .= "  \n";
        endforeach;

    endforeach;

    return $file_content;
}


function makeProfundis (array $index, string $file_content) : string
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
        $file_content .= "```php\n";


        foreach ($methods as $oneMethod) :
            $parameters = $oneMethod['ReflectionMethod']->getParameters();
            $parameters_string = '';

            $i = 0;
            foreach ($parameters as $oneP) :
                $parameters_string .= (++$i > 1) ? ', ' : '';

                if ($oneP->getType() !== null) :
                    $parameters_string .= getTypeAsString($oneP->getType()) . ' ';
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
                $representation .= ' : '.getTypeAsString($oneMethod['ReflectionMethod']->getReturnType());
            endif;

            $file_content .= "* ".$representation."  \n";
        endforeach;

        $file_content .= "```\n";

    endforeach;

    return $file_content;
}
