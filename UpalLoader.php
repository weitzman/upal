<?php

class UpalLoader implements PHPUnit_Runner_TestSuiteLoader {
  function suite() {
    $a=1;
  }

  public function load($suiteClassName, $suiteClassFile = '', $syntaxCheck = FALSE) {

  }

/**
  * @param  ReflectionClass  $aClass
  * @return ReflectionClass
  */
 public function reload(ReflectionClass $aClass)
 {
     return $aClass;
 }
}