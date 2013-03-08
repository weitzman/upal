*Update: Drupal 8 now uses PHPUnit for its unit tests. It still uses simpletest for its system tests. Upal's purpose as a showcase and proof of concept has been fulfilled and no further work is expected.*

Drupal's test suite based on PHPUnit (http://www.phpunit.de/).

Usage
--------
- Install PHPUnit (see below) and Drush (http://drupal.org/project/drush).
- Checkout or download a core Drupal that is to be tested (only tested with 8.x).
  -- Map http://upal to this Drupal in your web server config. If not possible,
     configure UPAL_WEB_URL in phpunit.xml (see Notes).
  -- Create an 'upal' database on your database server.
  -- If your db_url is not mysql://root:@127.0.0.1/upal, configure UPAL_DB_URL in
     phpunit.xml (see Notes).
- From the drupal root directory that is to be tested, run lines like:
    `phpunit --configuration /path/to/upal/phpunit.xml FilterUnitTestCase core/modules/filter/filter.test`
    `phpunit --debug --configuration /path/to/upal/phpunit.xml core/modules/book/book.test`

Notes
----------
- If customization is needed as per above, Copy phpunit.xml.dist to phpunit.xml and edit.

Install PHPUnit
----------------

Upal requires PHPUnit 3.5 or later; installing with PEAR is easiest.

- On Linux/OSX:
  sudo apt-get install php5-curl php-pear
  sudo pear upgrade --force PEAR
  sudo pear channel-discover pear.phpunit.de
  sudo pear channel-discover components.ez.no
  sudo pear channel-discover pear.symfony-project.com
  sudo pear install --alldeps phpunit/PHPUnit

- On Windows:
Download and save from go-pear.phar http://pear.php.net/go-pear.phar

  php -q go-pear.phar
  pear channel-discover pear.phpunit.de
  pear channel-discover components.ez.no
  pear channel-discover pear.symfony-project.com
  pear install --alldeps phpunit/PHPUnit
