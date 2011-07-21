Drupal's test suite based on PHPUnit (http://www.phpunit.de/).

Usage
--------
- Install PHPUnit [*]
- Optional. Copy phpunit.xml.dist to phpunit.xml and customize if needed.
- From the drupal root directory that is to be tested, run lines like:
    `phpunit --configuration /path/to/upal/phpnuit.xml FilterUnitTestCase modules/filter/filter.test`
    `phpunit --debug --configuration /path/to/upal/phpunit.xml ./modules/blog/blog.test`

Notes
----------

[*] Install PHPUnit:

Upal requires PHPUnit 3.5 or later; installing with PEAR is easiest.

On Linux/OSX:
---------

  sudo apt-get install php5-curl php-pear
  sudo pear upgrade --force PEAR
  sudo pear channel-discover pear.phpunit.de
  sudo pear channel-discover components.ez.no
  sudo pear channel-discover pear.symfony-project.com
  sudo pear install --alldeps phpunit/PHPUnit

On Windows:
-----------

Download and save from go-pear.phar http://pear.php.net/go-pear.phar

  php -q go-pear.phar
  pear channel-discover pear.phpunit.de
  pear channel-discover components.ez.no
  pear channel-discover pear.symfony-project.com
  pear install --alldeps phpunit/PHPUnit
