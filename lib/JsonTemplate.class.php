<?php

/*
 * since there are no callbacks nor namespaces in Php 5.2, all classes are prefixed with JsonTemplate
 * and callbacks are faked using the JsonTemplateCallback class
 * when version 5.3 is out it would be better to use namespaces and real callbacks
 */

/*
 * Base class for all exceptions in this module.
 * Thus you can catch JsonTemplateError to catch all exceptions thrown by this module
 */
class JsonTemplateError extends Exception
{
	function __construct($msg,$near=null)
	{
		/*
		This helps people debug their templates.

		If a variable isn't defined, then some context is shown in the traceback.
		TODO: Attach context for other errors.
		 */
		parent::__construct($msg);
		$this->near = $near;
		if($this->near){
			$this->message .= "\n\nNear: ".$this->near;
		}
	}

}

/* 
* Base class for errors that happen during the compilation stage
*/
class JsonTemplateCompilationError extends JsonTemplateError
{

}

/*
 * Base class for errors that happen when expanding the template.
 *
 * This class of errors generally involve the data array or the execution of
 * the formatters.
 */
class JsonTemplateEvaluationError extends JsonTemplateError
{

	function __construct($msg,$original_exception=null)
	{
		parent::__construct($msg);
		$this->original_exception = $original_exception;
	}
}

/*
 * A bad formatter was specified, e.g. {variable|BAD}
 */
class JsonTemplateBadFormatter extends JsonTemplateCompilationError
{

}

/*
 * Raised when formatters are required, and a variable is missing a formatter.
 */
class JsonTemplateMissingFormatter extends JsonTemplateCompilationError
{

}

/*
 * Raised when the Template options are invalid and it can't even be compiled.
 */
class JsonTemplateConfigurationError extends JsonTemplateCompilationError
{

}

/*
 * Syntax error in the template text.
 */
class JsonTemplateTemplateSyntaxError extends JsonTemplateCompilationError
{

}

/*
 * The template contains a variable not defined by the data dictionary.
 */
class JsonTemplateUndefinedVariable extends JsonTemplateCompilationError
{

}

/*
 * represents a callback since PHP has no equivalent
 */
abstract class JsonTemplateCallback
{
	abstract public function call();
}

// calls a function passing all the parameters
class JsonTemplateFunctionCallback extends JsonTemplateCallback
{
	protected $function = '';
	protected $args = array();

	function __construct()
	{
		$args = func_get_args();
		$this->function = array_shift($args);
		$this->args = $args;
	}

	function call()
	{
		$args = func_get_args();
		$args = array_merge($this->args,$args);
		return call_user_func_array($this->function,$args);
	}
}

// stores the first parameter in an array
class JsonTemplateStackCallback extends JsonTemplateCallback
{
	protected $stack = array();

	function call()
	{
		$this->stack[] = func_get_arg(0);
	}

	function get()
	{
		return $this->stack;
	}
}

class JsonTemplateModuleCallback extends JsonTemplateFunctionCallback
{
	function call()
	{
		$module = JsonTemplateModule::pointer();
		$args = func_get_args();
		$args = array_merge($this->args,$args);
		return call_user_func_array(array($module,$this->function),$args);
	}
}

/*
 * Receives method calls from the parser, and constructs a tree of JsonTemplateSection
 * instances.
 */

class JsonTemplateProgramBuilder
{
	/*
	more_formatters: A function which returns a function to apply to the
	value, given a format string.  It can return null, in which case the
	DefaultFormatters class is consulted.
	*/
	function __construct($more_formatters)
	{
		$this->current_block = new JsonTemplateSection();
		$this->stack = array($this->current_block);
		$this->more_formatters = $more_formatters;
	}

        // statement: Append a literal
	function Append($statement)
	{
		$this->current_block->Append($statement);
	}

        // The user's formatters are consulted first, then the default formatters.
	private function GetFormatter($format_str)
	{
    $formatter = null;
		$func = $this->more_formatters;
		if($func instanceof JsonTemplateCallback){
			$formatter = $func->call($format_str);
		}elseif(is_array($func)){
			$formatter = $func[$format_str];
		}elseif(function_exists($func)){
			$formatter = $func($format_str);
		}
		if(!$formatter){
			$formatter = JsonTemplateModule::pointer()->default_formatters[$format_str];
		}
		if($formatter){
			return $formatter;
		}else{
			throw new JsonTemplateBadFormatter(sprintf('%s is not a valid formatter', $format_str));
		}
	}

	function AppendSubstitution($name, $formatters)
	{
		foreach($formatters as $k=>$f){
			$formatters[$k] = $this->GetFormatter($f);
		}
		$this->current_block->Append(new JsonTemplateModuleCallback('DoSubstitute', $name, $formatters));

	}

    	// For sections or repeated sections
	function NewSection($repeated, $section_name)
	{
		$new_block = new JsonTemplateSection($section_name);
		if($repeated){
			$func = 'DoRepeatedSection';
		}else{
			$func = 'DoSection';
		}
		$this->current_block->Append(new JsonTemplateModuleCallback($func, $new_block));
		$this->stack[] = $new_block;
		$this->current_block = $new_block;
	}

	/*
	 * TODO: throw errors if the clause isn't appropriate for the current block
	 * isn't a 'repeated section' (e.g. alternates with in a non-repeated
	 * section)
	 */
	function NewClause($name)
	{
		$this->current_block->NewClause($name);
	}

	function EndSection()
	{
		array_pop($this->stack);
		$this->current_block = end($this->stack);
	}

	function Root()
	{
		return $this->current_block;
	}
}

// Represents a (repeated) section.
class JsonTemplateSection
{
	/*
	 * Args:
	 * section_name: name given as an argument to the section
	 */
	function __construct($section_name=null)
	{
		$this->section_name = $section_name;
		$this->current_clause = array();
	        $this->statements = array('default'=>&$this->current_clause);
	}

	function __toString()
	{
		try{
			return sprintf('<Block %s>', $this->section_name);
		}catch(Exception $e){
			return $e->getMessage();
		}
	}

	function Statements($clause='default')
	{
		return $this->statements[$clause];
	}

	function NewClause($clause_name)
	{
		$new_clause = array();
		$this->statements[$clause_name] = &$new_clause;
		$this->current_clause = &$new_clause;
	}

	// Append a statement to this block.
	function Append($statement)
	{
		array_push($this->current_clause, $statement);
	}
}


/*
 * Allows scoped lookup of variables.
 * If the variable isn't in the current context, then we search up the stack.
 */
class JsonTemplateScopedContext implements Iterator
{
	protected $positions = array();

	function __construct($context)
	{
		$this->stack = array($context);
		$this->name_stack = array('@');
	}

	function __toString()
	{
		return sprintf("<Context %s>",implode(" ",$this->name_stack));
	}

	function PushSection($name)
	{
		$end = end($this->stack);
		if(is_array($end)){
			if(isset($end[$name])){
				$new_context = $end[$name];
			}else{
				return false;
			}
		}elseif(is_object($end)){
			// since json_decode returns StdClass
			// check if scope is an object
			if(property_exists($end,$name)){
				$new_context = $end->$name;
			}else{
				return false;
			}
		}else{
			return false;
		}
		$this->name_stack[] = $name;
		$this->stack[] = $new_context;
		return $new_context;
	}

	function Pop()
	{
		array_pop($this->name_stack);
		return array_pop($this->stack);
	}

	function CursorValue()
	{
		return end($this->stack);
	}

	// Iterator functions
	// Assumes that the top of the stack is a list.
	// NOTE: Iteration alters scope
	function rewind() {
		$this->positions[] = 0;
		$this->stack[] = array();
	}

	function current() {
		return end($this->stack);
	}

	function key() {
		return end($this->positions);
	}

	function next() {
		++$this->positions[count($this->positions)-1];
	}

	function valid() {
		$len = count($this->stack);
		$pos = end($this->positions);
		$items = $this->stack[$len-2];
		if(is_array($items) && count($items)>$pos){
			$this->stack[$len-1] = $items[$pos];
			return true;
		}else{
			array_pop($this->stack);
			array_pop($this->positions);
			return false;
		}
	}

    	// Get the value associated with a name in the current context.  The current
    	// context could be an associative array or a StdClass object
	function Lookup($name)
	{
		$i = count($this->stack)-1;
		while(true){
			$context = $this->stack[$i];
			if(is_array($context)){
				if(!isset($context[$name])){
					$i -= 1;
				}else{
					return $context[$name];
				}
			}elseif(is_object($context)){
				if(!property_exists($context,$name)){
					$i -= 1;
				}else{
					return $context->$name;
				}
			}else{
				$i -= 1;
			}
			if($i<= -1){
				throw new JsonTemplateUndefinedVariable(sprintf('%s is not defined',$name));
			}
		}
	}
}


# See http://google-ctemplate.googlecode.com/svn/trunk/doc/howto.html for more
# escape types.
#
# Also, we might want to take a look at Django filters.
abstract class JsonTemplateFormatter
{
	abstract public function format($obj);
}

class HtmlJsonTemplateFormatter extends JsonTemplateFormatter
{
	function format($str)
	{
		return htmlspecialchars($str,ENT_NOQUOTES);
	}
}

class HtmlAttributeValueJsonTemplateFormatter extends JsonTemplateFormatter
{
	function format($str)
	{
		return htmlspecialchars($str);
	}

}

class RawJsonTemplateFormatter extends JsonTemplateFormatter
{
	function format($str)
	{
		return "${str}";
	}
}

class SizeJsonTemplateFormatter extends JsonTemplateFormatter
{
	# Used for the length of an array or a string
	function format($obj)
	{
		if(is_string($obj)){
			return strlen($obj);
		}else{
			return count($obj);
		}
	}
}

class UrlParamsJsonTemplateFormatter extends JsonTemplateFormatter
{
    	# The argument is an associative array, and we get a a=1&b=2 string back.
	function format($params)
	{
		if(is_array($parmas)){
			foreach($params as $k=>$v){
				$parmas[$k] = urlencode($k)."=".urlencode($v);
			}
			return implode("&",$params);
		}else{
			return urlencode($params);
		}
	}
}

class UrlParamValueJsonTemplateFormatter extends JsonTemplateFormatter
{
    	# The argument is a string 'Search query?' -> 'Search+query%3F'
	function format($param)
	{
		return urlencode($param);
	}

}

class JsonTemplateModule
{

	public $section_re = '/(repeated)?\s*(section)\s+(\S+)/';
	public $option_re = '/^([a-zA-Z\-]+):\s*(.*)/';
	public $option_names = array('meta','format-char','default-formatter');
	public $token_re_cache = array();

	public $default_formatters = array(
		'html'			=> 'HtmlJsonTemplateFormatter',
		'html-attr-value'	=> 'HtmlAttributeValueJsonTemplateFormatter',
		'htmltag'		=> 'HtmlAttributeValueJsonTemplateFormatter',
		'raw'			=> 'RawJsonTemplateFormatter',
		'size'			=> 'SizeJsonTemplateFormatter',
		'url-params'		=> 'UrlParamsJsonTemplateFormatter',
		'url-param-value'	=> 'UrlParamValueJsonTemplateFormatter',
		'str'			=> 'RawJsonTemplateFormatter',
		'default_formatter'	=> 'RawJsonTemplateFormatter',
	);

	static function &pointer()
	{
		static $singleton = null;
		if(!$singleton){       
			$singleton = new JsonTemplateModule();
		}
		return $singleton;
	}

	/*
	 * Split and validate metacharacters.
	 *
	 * Example: '{}' -> ('{', '}')
	 *
	 * This is public so the syntax highlighter and other tools can use it.
	 */
	function SplitMeta($meta)
	{
		$n = strlen($meta);
		if($n % 2 == 1){
			throw new JsonTemplateConfigurationError(sprintf('%s has an odd number of metacharacters', $meta));
		}
		return array(substr($meta,0,$n/2),substr($meta,$n/2));
	}

	/* Return a regular expression for tokenization.
	 * Args:
	 *   meta_left, meta_right: e.g. '{' and '}'
	 *
	 * - The regular expressions are memoized.
	 * - This function is public so the syntax highlighter can use it.
	 */
	function MakeTokenRegex($meta_left, $meta_right)
	{
		$key = $meta_left.$meta_right;
		if(!in_array($key,array_keys($this->token_re_cache))){
			$this->token_re_cache[$key] = '/('.quotemeta($meta_left).'.+?'.quotemeta($meta_right).'\n?)/';
		}
		return $this->token_re_cache[$key];
	}

	/*
	  Compile the template string, calling methods on the 'program builder'.

	  Args:
	    template_str: The template string.  It should not have any compilation
		options in the header -- those are parsed by FromString/FromFile
	    options: array of compilation options, possible keys are:
		    meta: The metacharacters to use
		    more_formatters: A function which maps format strings to
			*other functions*.  The resulting functions should take a data
			array value (a JSON atom, or an array itself), and return a
			string to be shown on the page.  These are often used for HTML escaping,
			etc.  There is a default set of formatters available if more_formatters
			is not passed.
		    default_formatter: The formatter to use for substitutions that are missing a
			formatter.  The 'str' formatter the "default default" -- it just tries
			to convert the context value to a string in some unspecified manner.
	    builder: Something with the interface of JsonTemplateProgramBuilder

	  Returns:
	    The compiled program (obtained from the builder)

	  Throws:
	    The various subclasses of JsonTemplateCompilationError.  For example, if
	    default_formatter=null, and a variable is missing a formatter, then
	    MissingFormatter is raised.

	  This function is public so it can be used by other tools, e.g. a syntax
	  checking tool run before submitting a template to source control.
	*/
	function CompileTemplate($template_str, $options=array(), $builder=null)
	{
		$default_options = array(
			'meta'			=> '{}',
			'format_char' 		=> '|',
			'more_formatters'	=> null,
			'default_formatter'	=> 'str',
		);
		if(is_array($options)){
			$options = array_merge($default_options,$options);
		}elseif(is_object($options)){
			$obj = $options;
			$options = $default_options;
			foreach($options as $k=>$v){
				if(property_exists($obj,$k)){
					$options[$k] = $obj->$k;
				}
			}
		}else{
			$options = $default_options;
		}

		if(!$builder){
			$builder = new JsonTemplateProgramBuilder($options['more_formatters']);
		}
		list($meta_left,$meta_right) = $this->SplitMeta($options['meta']);

		# : is meant to look like Python 3000 formatting {foo:.3f}.  According to
		# PEP 3101, that's also what .NET uses.
		# | is more readable, but, more importantly, reminiscent of pipes, which is
		# useful for multiple formatters, e.g. {name|js-string|html}
		if(!in_array($options['format_char'],array(':','|'))){
			throw new JsonTemplateConfigurationError(sprintf('Only format characters : and | are accepted (got %s)',$options['format_char']));
		}

		# Need () for preg_split
		$token_re = $this->MakeTokenRegex($meta_left, $meta_right);
		$tokens = preg_split($token_re, $template_str, -1, PREG_SPLIT_DELIM_CAPTURE);

		# If we go to -1, then we got too many {end}.  If end at 1, then we're missing
		# an {end}.
		$balance_counter = 0;
		foreach($tokens as $i=>$token){
			if(($i % 2) == 0){
				if($token){
					$builder->Append($token);
				}
			}else{
				$had_newline = false;
				if(substr($token,-1)=="\n"){
				 	$token = substr($token,0,-1);
					$had_newline = true;
				}

				assert('substr($token,0,strlen($meta_left)) == $meta_left;');
				assert('substr($token,-1*strlen($meta_right)) == $meta_right;');

				$token = substr($token,strlen($meta_left),-1*strlen($meta_right));


				// if it is a comment
				if(substr($token,0,1)=="#"){
					continue;
				}

				// if it's a keyword directive
				if(substr($token,0,1)=='.'){
					$token = substr($token,1);
					switch($token){
					case 'meta-left':
						$literal = $meta_left;
						break;
					case 'meta-right':
						$literal = $meta_right;
						break;
					case 'space':
						$literal = ' ';
						break;
					case 'tab':
						$literal = "\t";
						break;
					case 'newline':
						$literal = "\n";
						break;
					}
				}
				if(isset($literal) && $literal){
					$builder->Append($literal);
					continue;
				}

				if(preg_match($this->section_re,$token,$match)){
					$builder->NewSection($match[1],$match[3]);
					$balance_counter += 1;
					continue;
				}

				if(in_array($token,array('or','alternates with'))){
					$builder->NewClause($token);
					continue;
				}

				if($token == 'end'){
					$balance_counter -= 1;
					if($balance_counter < 0){
						# TODO: Show some context for errors
						throw new JsonTemplateTemplateSyntaxError(sprintf(
							'Got too many %send%s statements.  You may have mistyped an '.
							"earlier 'section' or 'repeated section' directive.",
							$meta_left, $meta_right));
					}
					$builder->EndSection();
					if($had_newline){
						$builder->Append("\n");
					}
					continue;
				}

				# Now we know the directive is a substitution.
				$parts = explode($options['format_char'],$token);
				if(count($parts) == 1){
					if(!$options['default_formatter']){
						throw new JsonTemplateMissingFormatter('This template requires explicit formatters.');
						# If no formatter is specified, the default is the 'str' formatter,
						# which the user can define however they desire.
					}
					$name = $token;
					$formatters = array($options['default_formatter']);
				}else{
					$name = array_shift($parts);
					$formatters = $parts;
				}

				$builder->AppendSubstitution($name,$formatters);
				if($had_newline){
					$builder->Append("\n");
				}
			}
		}

		if($balance_counter != 0){
			throw new JsonTemplateTemplateSyntaxError(sprintf('Got too few %send%s statements', $meta_left, $meta_right));
		}
		return $builder->Root();
	}


  	// Like FromString, but takes a file.
	static function FromFile($f, $constructor='JsonTemplate')
	{
		if(is_string($f)){
			$string = file_get_contents($f);
		}else{
			while(!feof($f)){
				$string .= fgets($f,1024)."\n";
			}
		}
		return $this->FromString($string,$constructor);
	}

	/*
	Parse a template from a string, using a simple file format.

	This is useful when you want to include template options in a data file,
	rather than in the source code.

	The format is similar to HTTP or E-mail headers.  The first lines of the file
	can specify template options, such as the metacharacters to use.  One blank
	line must separate the options from the template body.

	Example:

	default-formatter: none
	meta: {{}}
	format-char: :
	<blank line required>
	Template goes here: {{variable:html}}
	*/

	function FromString($string, $constructor='JsonTemplate')
	{
		$options = array();
		$lines = explode("\n",$string);
		foreach($lines as $k=>$line){
			if(preg_match($this->option_re,$line,$match)){
			# Accept something like 'Default-Formatter: raw'.  This syntax is like
			# HTTP/E-mail headers.
				$name = strtolower($match[1]);
				$value = trim($match[2]);
				if(in_array($name,$this->option_names)){
					$name = str_replace('-','_',$name);
					if($name == 'default_formatter' && strtolower($value) == 'none'){
						$value = null;
					}
					$options[$name] = $value;
				}else{
					break;
				}
			}else{
				break;
			}
		}

		if($options){
			if(trim($line)){
				throw new JsonTemplateCompilationError(sprintf(
					'Must be one blank line between template options and body (got %s)',$line));
			}
			$body = implode("\n",array_slice($lines,$k+1));
		}else{
			# There were no options, so no blank line is necessary.
			$body = $string;
		}
		return new $constructor($body,$options);
	}

	// {repeated section foo}
	function DoRepeatedSection($args, $context, $callback)
	{
		$block = $args;

		if($block->section_name == '@'){
			# If the name is @, we stay in the enclosing context, but assume it's a
			# list, and repeat this block many times.
			$items = $context->CursorValue();
			if(!is_array($items)){
				throw new JsonTemplateEvaluationError(sprintf('Expected a list; got %s', gettype($items)));
			}
			$pushed = false;
		}else{
			$items = $context->PushSection($block->section_name);
			$pushed = true;
		}

		if($items){
			$last_index = count($items) - 1;
			$statements = $block->Statements();
			$alt_statements = $block->Statements('alternates with');
			# NOTE: Iteration mutates the context!
			foreach($context as $i=>$data){
				# Execute the statements in the block for every item in the list.  Execute
				# the alternate block on every iteration except the last.
				# Each item could be an atom (string, integer, etc.) or a dictionary.
				$this->Execute($statements, $context, $callback);
				if($i != $last_index){
					$this->Execute($alt_statements, $context, $callback);
				}
			}
		}else{
			$this->Execute($block->Statements('or'), $context, $callback);
		}

		if($pushed){
			$context->Pop();
		}

	}

	// {section foo}
	function DoSection($args, $context, $callback)
	{
		$block = $args;
		# If a section isn't present in the dictionary, or is None, then don't show it
		# at all.
		if($context->PushSection($block->section_name)){
			$this->Execute($block->Statements(), $context, $callback);
			$context->Pop();
		}else{
			# empty list, none, false, etc.
			# $context->Pop();
			$this->Execute($block->Statements('or'), $context, $callback);
		}
	}

	// Variable substitution, e.g. {foo}
	function DoSubstitute($name, $formatters, $context, $callback=null)
	{
		if(!($context instanceof JsonTemplateScopedContext)){
			throw new JsonTemplateEvaluationError(sprintf('Error not valid context %s',$context));
		}
		# So we can have {.section is_new}new since {@}{.end}.  Hopefully this idiom
		# is OK.

		if($name == '@'){
			$value = $context->CursorValue();
		}else{
			try{
				$value = $context->Lookup($name);
			}catch(JsonTemplateUndefinedVariable $e){
				throw $e;
			}catch(Exception $e){
				throw new JsonTemplateEvaluationError(sprintf(
					'Error evaluating %s in context %s: %s', $name, $context, $e->getMessage()
				));
			}
		}

		foreach($formatters as $f){
			try{
				$formatter = new $f();
				$value = $formatter->format($value);
			}catch(Exception $e){
				throw new JsonTemplateEvaluationError(sprintf(
					'Formatting value %s with formatter %s raised exception: %s',
					 $value, $f, $e), $e);
			}
		}
		# TODO: Require a string/unicode instance here?
		if(!$value){
			throw new JsonTemplateUndefinedVariable(sprintf('Evaluating %s gave null value', $name));
		}
		if($callback instanceof JsonTemplateCallback){
			return $callback->call($value);
		}elseif(is_string($callback)){
			return $callback($value);
		}else{
			return $value;
		}
	}

	/*
	 * Execute a bunch of template statements in a ScopedContext.
  	 * Args:
         * callback: Strings are "written" to this callback function.
	 *
  	 * This is called in a mutually recursive fashion.
	 */
	function Execute($statements, $context, $callback)
	{
		if(!is_array($statements)){
			$statements = array($statements);
		}
		foreach($statements as $i=>$statement){
			if(is_string($statement)){
				if($callback instanceof JsonTemplateCallback){
					$callback->call($statement);
				}elseif(is_string($callback)){
					$callback($statement);
				}
			}else{
				try{
					if($statement instanceof JsonTemplateCallback){
						$statement->call($context, $callback);
					}
				}catch(JsonTemplateUndefinedVariable $e){
					# Show context for statements
					$start = max(0,$i-3);
					$end = $i+3;
					$e->near = array_slice($statements,$start,$end);
					throw $e;
				}
			}
		}
	}

	/*
	Free function to expands a template string with a data dictionary.

	This is useful for cases where you don't care about saving the result of
	compilation (similar to re.match('.*', s) vs DOT_STAR.match(s))
	*/
	function expand($template_str, $data, $options=array())
	{
		$t = new JsonTemplate($template_str, $options);
		return $t->expand($data);
	}

}


/*
Represents a compiled template.

Like many template systems, the template string is compiled into a program,
and then it can be expanded any number of times.  For example, in a web app,
you can compile the templates once at server startup, and use the expand()
method at request handling time.  expand() uses the compiled representation.

There are various options for controlling parsing -- see CompileTemplate.
Don't go crazy with metacharacters.  {}, [], {{}} or <> should cover nearly
any circumstance, e.g. generating HTML, CSS XML, JavaScript, C programs, text
files, etc.
*/

class JsonTemplate
{
	protected $program;
	/*
	Args:
	template_str: The template string.

	It also accepts all the compile options that CompileTemplate does.
	*/
	function __construct($template_str, $compile_options=array(), $builder=null)
	{
		if(is_string($compile_options)){
			$compile_options = json_decode($compile_options);
		}
		$this->compile_options = $compile_options;
		$this->template_str = $template_str;
	    	$this->program = JsonTemplateModule::pointer()->CompileTemplate($template_str, $compile_options, $builder);
	}

	#
	# Public API
	#

	/*
	Low level method to expands the template piece by piece.

	Args:
	data: The JSON data dictionary.
	callback: A callback which should be called with each expanded token.

	Example: You can pass 'f.write' as the callback to write directly to a file
	handle.
	 */
	function render($data, $callback=null)
	{
		if(is_string($data)){
			$data = json_decode($data);
		}
		return JsonTemplateModule::pointer()->Execute($this->program->Statements(), new JsonTemplateScopedContext($data), $callback);
	}

	/*
	Expands the template with the given data dictionary, returning a string.

	This is a small wrapper around render(), and is the most convenient
	interface.

	Args:
	data_dict: The JSON data dictionary.

	Returns:
	The return value could be a str() or unicode() instance, depending on the
	the type of the template string passed in, and what the types the strings
	in the dictionary are.
	 */
	function expand($data)
	{
		return implode('',$this->tokenstream($data));
	}

	/*
	returns a list of tokens resulting from expansion.
	*/
	function tokenstream($data)
	{
		$c = new JsonTemplateStackCallback();
		$tokens = $this->render($data,$c);
		return $c->get();
	}
}

// run as script for tests
if(isset($argv) && is_array($argv)){
	if(count($argv)<=1){
		print "usage: ".$argv[0]." 'Hello [var]!' '{\"var\":\"World\"}' '{\"meta\":\"[]\"}'\n";
	}
	if(isset($argv[2])){
		$data = json_decode($argv[2]);
	}else{
		$data = array();
	}
	if(isset($argv[3])){
		$options = json_decode($argv[3]);
	}else{
		$options = array();
	}

	try{
		print JsonTemplateModule::expand($argv[1],$data,$options);
	}catch(Exception $e){
		$class = preg_replace('/^JsonTemplate/','',get_class($e));
		print "EXCEPTION: ".$class.": ".$e->getMessage()."\n";
	}
}

?>
