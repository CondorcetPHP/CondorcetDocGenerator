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
    $oneEntry = $entry ;
    $oneEntry['class'] = $class;
    $oneEntry['inDoc'] = true;

    if(!method_exists('CondorcetPHP\Condorcet\\'.$oneEntry['class'], $oneEntry['name'])) :
        print "The method does not exist >> ".$oneEntry['class']." >> ".$oneEntry['name']."\n";
    else :
        $oneEntry['ReflectionMethod'] = new ReflectionMethod ('CondorcetPHP\Condorcet\\'.$oneEntry['class'],$oneEntry['name']);

        checkEntry($oneEntry);
    endif;

    $index[$oneEntry['class']][$oneEntry['name']] = $oneEntry;
  endforeach;
endforeach;

$inDoc = 0;
$non_inDoc = 0;

foreach ($FullClassList as $FullClass) :
    $methods = (new ReflectionClass($FullClass))->getMethods(ReflectionMethod::IS_PUBLIC);
    $shortClass = simpleClass($FullClass);

    foreach ($methods as $oneMethod) :
        if ( !isset($index[$shortClass][$oneMethod->name]) && !$oneMethod->isInternal()) :
            $non_inDoc++;

            if (!empty($apiAttribute = $oneMethod->getAttributes(PublicAPI::class)) && $oneMethod->getNumberOfParameters() > 0 && (empty($apiAttribute[0]->getArguments()) || in_array(simpleClass($oneMethod->class),$apiAttribute[0]->getArguments(), true))) :
                var_dump('Method Has Public API attribute and parameters, but not in doc.yaml file: '.$oneMethod->getDeclaringClass()->getName().'->'.$oneMethod->getName());
            endif;

            $index[$shortClass][$oneMethod->name]['inDoc'] = false;
            $index[$shortClass][$oneMethod->name]['ReflectionMethod'] = $oneMethod;
            $index[$shortClass][$oneMethod->name]['class'][] = $shortClass;

        else :
            $inDoc++;


            if (empty($oneMethod->getAttributes(PublicAPI::class)) && $oneMethod->getDeclaringClass()->getNamespaceName() !== "") :
                var_dump('Method not has API attribute, but is in doc.yaml file: '.$oneMethod->getDeclaringClass()->getName().'->'.$oneMethod->getName());
            endif;

            if ( empty($oneMethod->getAttributes(Description::class)) && $oneMethod->getDeclaringClass()->getNamespaceName() !== "") :
                var_dump('Description Attribute is empty: '.$oneMethod->getDeclaringClass()->getName().'->'.$oneMethod->getName());
            endif;
        endif;

        // Write Markdown
        if (!empty($apiAttribute = $oneMethod->getAttributes(PublicAPI::class)) && (empty($apiAttribute[0]->getArguments()) || in_array(simpleClass($oneMethod->class),$apiAttribute[0]->getArguments(), true)) ) :
            $path = $pathDirectory . str_replace("\\", "_", simpleClass($oneMethod->class)) . " Class/";

            if (!is_dir($path)) :
                mkdir($path);
            endif;

            file_put_contents($path.makeFilename($oneMethod), createMarkdownContent($oneMethod, $index[$shortClass][$oneMethod->name] ?? null));
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

function makeFilename (\ReflectionMethod $method) : string
{
    return  getModifiersName($method).
            " ".
            str_replace("\\", "_", simpleClass($method->class))."--".$method->name.
            ".md";
}

function simpleClass (string $fullClassName) : string
{
    return str_replace('CondorcetPHP\\Condorcet\\','',$fullClassName);
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


function createMarkdownContent (\ReflectionMethod $method, ?array $entry) : string
{
    // Header
    
    $md =
"## ".
getVisibility($method).
($method->isStatic() ? " static " : " "). 
simpleClass($method->class)."::".$method->name.     "

### Description    

".makeRepresentation($method)."

".$method->getAttributes(Description::class)[0]->getArguments()[0]."\n    ";


    // Input

if ($method->getNumberOfParameters() > 0) :
    foreach ($method->getParameters() as $key => $value ) :

$md .= "

##### **".$value->getName().":** *".getTypeAsString($value->getType())."*   
".((isset($entry['input'][$value->getName()]['text'])) ? $entry['input'][$value->getName()]['text'] : "")."    
";
    endforeach;
endif;

    
    // Return Value

if (!empty($method->getAttributes(FunctionReturn::class))) :


    $md .= "

### Return value:   

*(".getTypeAsString($method->getReturnType()).")* ".$method->getAttributes(FunctionReturn::class)[0]->getArguments()[0]."\n\n";
endif;

    // Related methods

    if(!empty($method->getAttributes(Related::class))) :

        $md .=
"
---------------------------------------

### Related method(s)      

";

        foreach ($method->getAttributes(Related::class)[0]->getArguments() as $value) :

            if ($value === simpleClass($method->class).'::'.$method->name) : continue; endif;

            $md .= "* ".cleverRelated($value)."    
";
        endforeach;

    endif;

    if(!empty($method->getAttributes(Examples::class))) :

        $md .=
"
---------------------------------------

### Examples and explanation

";

        foreach ($method->getAttributes(Examples::class)[0]->getArguments() as $value) :
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

function makeRepresentation (\ReflectionMethod $method, bool $link = false) : string
{
    if (!$link) :
        return computeRepresentationAsPHP($method);
    else :
        return computeRepresentationAsForIndex($method);
    endif;
}

function computeRepresentationAsPHP (\ReflectionMethod $method) : string
{

    $option = false;
    $str = '(';
    $i = 0;


    if ($method->getNumberOfParameters() > 0) :
        foreach ($method->getParameters() as $value) :
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
".getModifiersName($method).' '.(($method->isStatic())?"":'$').simpleClass($method->class).(($method->isStatic())?"::":' -> ').$method->name." ".$str. ( (getTypeAsString($method->getReturnType()) !== null) ? " : ".getTypeAsString($method->getReturnType()) : "" )."
```";
}

function getModifiersName (\ReflectionMethod $method) : string
{
    return implode(' ', Reflection::getModifierNames($method->getModifiers()));
}

function computeRepresentationAsForIndex (\ReflectionMethod $method) : string
{
    return  getModifiersName($method).
            " ".
            simpleClass($method->class).
            (($method->isStatic())?"::":'->').
            $method->name.
            " (".( ($method->getNumberOfParameters() > 0) ? '...' : '').")";
}

function checkEntry(array $entry) : void
{
    $parameters = $entry['ReflectionMethod']->getParameters();

    $iec = isset($entry['input']) ? count($entry['input']) : 0;
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
                return strnatcmp($a['ReflectionMethod']->name,$b['ReflectionMethod']->name);
            elseif ($a['ReflectionMethod']->isStatic() && !$b['ReflectionMethod']->isStatic()) :
                return -1;
            else :
                return 1;
            endif;
        });

        $i = 0;
        foreach ($methods as $oneMethod) :
            if (empty($apiAttribute = $oneMethod['ReflectionMethod']->getAttributes(PublicAPI::class)) || (!empty($apiAttribute[0]->getArguments()) && !in_array(simpleClass($oneMethod['ReflectionMethod']->class),$apiAttribute[0]->getArguments(), true))) :
                continue;
            else :
                if (++$i === 1) :
                    $file_content .= "\n";
                    $file_content .= '### CondorcetPHP\Condorcet\\'.$class." Class  \n\n";
                endif;


                $url = str_replace("\\","_",simpleClass($oneMethod['ReflectionMethod']->class)).' Class/'.getVisibility($oneMethod['ReflectionMethod']).' '.(($oneMethod['ReflectionMethod']->isStatic())?'static ':'') . str_replace("\\","_",simpleClass($oneMethod['ReflectionMethod']->class)."--". $oneMethod['ReflectionMethod']->name) . '.md' ;
                $url = str_replace(' ', '%20', $url);

                $file_content .= "* [".makeRepresentation($oneMethod['ReflectionMethod'], true)."](".$url.")";

                if (isset($oneMethod['ReflectionMethod']) && $oneMethod['ReflectionMethod']->hasReturnType()) :
                    $file_content .= ' : '.getTypeAsString($oneMethod['ReflectionMethod']->getReturnType());
                endif;


                $file_content .= "  \n";
            endif;
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
