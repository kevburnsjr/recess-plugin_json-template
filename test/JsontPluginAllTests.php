<?php
require_once 'PHPUnit/Framework.php';
require_once 'helpers/JsontTest.php';

class JsontPluginAllTests
{
  public static function suite()
  {
    $suite = new PHPUnit_Framework_TestSuite('plugins.jsont');

    $suite->addTestSuite('JsontTest');

    return $suite;
  }
}
?>