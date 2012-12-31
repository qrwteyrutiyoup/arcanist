<?php

/*
 * Copyright 2012 Sergio Correia <sergio@correia.cc>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Very basic 'BusterJS' unit test engine wrapper.
 *
 * To use it, configure the 'unit.engine' key in your .arcconfig
 * and either have buster accessible through the path or configure
 * where the binary is located at with the keys 'unit.busterjs.prefix'
 * and 'unit.busterjs.bin'.
 *
 * You must also indicate the config file with the 'unit.busterjs.config'
 * key.
 *
 * Example with buster available in the path (.arcconfig):
 *
 * (...)
 * "unit.engine": "BusterJSTestEngine",
 * "unit.busterjs.config": ".buster.js"
 *
 * @group unitrun
 */
final class BusterJSTestEngine extends ArcanistBaseUnitTestEngine {

  private function getBusterJSOptions() {
    $working_copy = $this->getWorkingCopy();
    $options = '--reporter xml';
    $config = $working_copy->getConfig('unit.busterjs.config');

    if ($config !== null) {
      $config = Filesystem::resolvePath($config,
                                        $working_copy->getProjectRoot());

      if (!Filesystem::pathExists($config)) {
        throw new ArcanistUsageException(
          "Unable to find the config file defined by 'unit.busterjs.config'. ".
          "Make sure that the path is correct.");
      }

      $options .= ' --config '.$config;
    }

    return $options;
  }

  private function getBusterJSPath() {
    $working_copy = $this->getWorkingCopy();
    $prefix = $working_copy->getConfig('unit.busterjs.prefix');
    $bin = $working_copy->getConfig('unit.busterjs.bin');

    if ($bin === null) {
      $bin = "buster-test";
    }

    if ($prefix !== null) {
      $bin = $prefix."/".$bin;

      if (!Filesystem::pathExists($bin)) {
        throw new ArcanistUsageException(
          "Unable to find BusterJS binary in a specified directory. Make sure ".
          "that 'unit.busterjs.prefix' and 'unit.busterjs.bin' keys are set ".
          "correctly. If you'd rather use a copy of BusterJS installed ".
          "globally, you can just remove these keys from your .arcconfig");
      }

      return $bin;
    }

    // Look for globally installed BusterJS
    $cmd = (phutil_is_windows()) ? 'where %s' : 'which %s';
    list($err) = exec_manual($cmd, $bin);
    if ($err) {
      throw new ArcanistUsageException(
        "BusterJS does not appear to be installed on this system. Install it ".
        "(e.g., with 'npm install buster -g') or configure ".
        "'unit.busterjs.prefix' in your .arcconfig to point to the directory ".
        "where it resides.");
    }

    return $bin;
  }

  private function parseBusterJSResults($raw_results) {
    // checking if there were any errors
    if (strlen($raw_results[2]) > 0) {
      throw new Exception($raw_results[2]);
    }

    // now to parse the received XML
    $xmldata = $raw_results[1];
    $results = array();

    $suites = simplexml_load_string($xmldata);

    if ($suites === FALSE) {
      // maybe there is no test file specified in the config file
      // should throw an exception?
      return array();
    }

    foreach ($suites as $suite) {
      foreach ($suite as $testcase) {
        $classname = $testcase['classname'];
        $time = (float) $testcase['time'];
        $name = $testcase['name'];

        // TODO: implement coverage
        $coverage = null;

        if ($testcase->failure) {
          $userdata = (string) $testcase->failure[0];
          $status = ArcanistUnitTestResult::RESULT_FAIL;
        } else {
          $userdata = "";
          $status = ArcanistUnitTestResult::RESULT_PASS;
        }

        $ret = new ArcanistUnitTestResult();
        $ret->setName($classname . "." . $name);
        $ret->setResult($status);
        $ret->setDuration($time);
        $ret->setCoverage($coverage);
        $ret->setUserData($userdata);

        $results[] = $ret;
      }
    }

    return $results;
  }

  public function run() {
    $busterjs_bin = $this->getBusterJSPath();
    $busterjs_options = $this->getBusterJSOptions();

    // changing to project root directory if needed
    $cwd = getcwd();
    $rootdir = $this->getWorkingCopy()->getProjectRoot();
    if (strcmp($cwd, $rootdir) != 0 && !chdir($rootdir)) {
      throw new Exception("Please run the unit tests from the project root ".
        "directory.");
    }

    $exec_ret = new ExecFuture("{$busterjs_bin} ${busterjs_options}");
    $results = $this->parseBusterJSResults($exec_ret->resolve());
    return $results;
  }
}
