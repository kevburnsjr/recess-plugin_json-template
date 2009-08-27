<?php
Library::import('Jsont.JsontPlugin');
Library::import('Jsont.helpers.Jsont');

class JsontTest extends PHPUnit_Framework_TestCase {

  function setUp() {
    Jsont::addPath(dirname(__FILE__) . '/test-jsont/');
  }

  function testNothing() {
    $content = Jsont::draw('shout');
    $this->assertEquals('shouted', $content);
  }

  function testArray() {
    $content = Jsont::draw('echo', array('test' => 'array worked'));
    $this->assertEquals('array worked', $content);
  }

  function testObject() {
    $obj = new stdClass;
    $obj->test = 'object worked';
    $content = Jsont::draw('echo', $obj);
    $this->assertEquals('object worked', $content);
  }

  function testJsonString() {
    $content = Jsont::draw('echo', '{"test":"json worked"}');
    $this->assertEquals('json worked', $content);
  }

  function testRequireTemplateName() {
    try {
      $content = Jsont::draw('', array());
      $this->fail('Should throw RecessFrameworkException');
    } catch(RecessFrameworkException $e) {
      $this->assertTrue(true);
    } catch(Exception $e) {
      $this->fail('Should throw RecessFrameworkException. Threw: ' . get_class($e));
    }
  }

}

?>