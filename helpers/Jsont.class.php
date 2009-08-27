<?php
Library::import('recess.lang.PathFinder');
Library::import('Jsont.lib.JsonTemplate');
Library::import('recess.framework.AbstractHelper');

/**
 * A JSON Template is a template that is written specifically to be combined 
 * with a JSON object.
 *
 * http://code.google.com/p/json-template/
 * 
 * Json Templates require the extension: '.html.jsont'
 * 
 * @author Kev Burns
 */
class Jsont extends AbstractHelper{
  
	/**
	 * File extension for template files
	 * @var ext
	 */
	protected static $ext = '.html.jsont';

// - --- ----- ------- -----  --- -
// - EXTRACT
// - 

    /**
     * Used to locate template files
     * @var Paths
     */
    private static $paths = false;
    
    /**
     * Initialize the helper class by registering
     * the application's views directory as a path.
     * 
     * @param AbstractView
     */
    public static function init(AbstractView $view = null) {
      self::setPathFinder(Application::active()->viewPathFinder());
    }
    
    /**
     * Add a directory to be checked for the existance of templates.
     * Paths are checked in the reverse order of their being added so
     * that the most specific paths are checked first.
     * @param string $path
     */
    public static function addPath($path) {
      if(!self::$paths instanceof PathFinder) {
        // To-do: Cache Paths
        self::$paths = new PathFinder();
      }
      self::$paths->addPath($path);
    }
    
    /**
     * Set the PathFinder to use when looking for templates
     * @param PathFinder $pathFinder
     */
    public static function setPathFinder(PathFinder $pathFinder) {
      self::$paths = $pathFinder;
    }
    
    protected static $loaded = array();
    
    public static function getPath($templateName) {
      if(self::$paths === false) {
        self::init();
      }
      
      $filePath = self::$paths->find($templateName . self::$ext);
      if($filePath === false) {
        throw new Exception('Could not locate template: ' . $templateName);
      }
      
      return $filePath;
    
    }
    
    /**
     * Read a template file and return the file's contents.
     * 
     * @param string The name of the tempate file relative to registered paths.
     * @return string The contents of the template file
     */
    public static function body($templateName) {
      $path = self::getPath($templateName);

      return file_get_contents($path);
    }
    
// - 
// - --- ----- ------- ----- --- -

	/**
	 * Draw a part by passing a key/value array where the keys match the
	 * part's input variable names.
	 * 
	 * @param string The name of the part template.
	 * @param array Key/values according to part's input(s).
	 * @return boolean True if successful, throws exception if unsuccessful.
	 */
	public static function draw($templateName = '', $context = array()) {
		if($templateName === '') {
			throw new RecessFrameworkException("First parameter 'partPath' must not be empty.", 1);
		}
		
		$body = self::body($templateName);
    $template = new JsonTemplate($body);
    return $template->expand($context);
	}
  
}
?>