<?php
declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link          https://webrider.de/
 * @since         1.0.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace CakephpTestSuiteLight;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\Fixture\FixtureManager as BaseFixtureManager;
use Cake\TestSuite\TestCase;
use CakephpTestSuiteLight\Sniffer\SnifferRegistry;
use Exception;
use function strpos;

/**
 * Class FixtureManager
 * @package CakephpTestSuiteLight
 */
class FixtureManager extends BaseFixtureManager
{
    /**
     * @var bool
     */
    private static $_configIsLoaded = false;

    /**
     * @var array|null
     */
    private $activeConnections;

    /**
     * FixtureManager constructor.
     * The config file test_suite_light is being loaded
     * This config file is deprecated. Configure the suite
     * at the connection level.
     */
    public function __construct()
    {
        $this->loadConfig();
    }

    /**
     * @param string $name
     * @return ConnectionInterface
     */
    public function getConnection($name = 'test')
    {
        return ConnectionManager::get($name);
    }

    /**
     * @return void
     */
    public function aliasConnections()
    {
        $this->_aliasConnections();
    }

    /**
     * Scan all test connections and truncate the dirty tables
     * @return void
     */
    public function truncateDirtyTables(): void
    {
        foreach ($this->getActiveConnections() as $connection) {
            SnifferRegistry::get($connection)->truncateDirtyTables();
        }
    }

    /**
     * @param string $connectionName
     * @param array  $ignoredConnections
     *
     * @return bool
     */
    public function skipConnection(string $connectionName, array $ignoredConnections): bool
    {
        // CakePHP 4 solves a DebugKit issue by creating an Sqlite connection
        // in tests/bootstrap.php. This connection should be ignored.
        if ($connectionName === 'test_debug_kit' || in_array($connectionName, $ignoredConnections)) {
            return true;
        }

        if ((ConnectionManager::getConfig($connectionName)['skipInTestSuiteLight'] ?? false) === true) {
            return true;
        }

        if ($connectionName === 'test' || strpos($connectionName, 'test_') === 0) {
            return false;
        }

        return true;
    }

    /**
     * Load the mapping between the database drivers
     * and the table truncators.
     * Add your own truncators for a driver not being covered by
     * the package in your test_suite_light.php config file
     * @deprecated The configuration file test_suite_light.php is deprecated
     */
    public function loadConfig(): FixtureManager
    {
        if (!self::$_configIsLoaded) {
            try {
                if (Configure::load('test_suite_light')) {
                    throw new \PHPUnit\Framework\Exception(
                        "The test_suite_light.php configuration file is deprecated.\n" .
                        "See https://github.com/vierge-noire/cakephp-test-suite-light#cakephp-test-suite-light.\n"
                    );
                }
            }
            catch (\PHPUnit\Framework\Exception $exception) {
                echo $exception->getMessage();
            }
            catch (Exception $exception) {}
            self::$_configIsLoaded = true;
        }

        return $this;
    }

    /**
     * Get the appropriate sniffer and drop all tables
     * @param string $connectionName
     * @return void
     */
    public function dropTables(string $connectionName): void
    {
        SnifferRegistry::get($connectionName)->dropTables(
            SnifferRegistry::get($connectionName)->fetchAllTables()
        );
    }

    /**
     * Initialize all connections used by the manager
     * @return array
     */
    public function fetchActiveConnections(): array
    {
        $connections = ConnectionManager::configured();
        $ignoredConnections = Configure::read('TestSuiteLightIgnoredConnections', []);
        foreach ($connections as $i => $connectionName) {
            if ($this->skipConnection($connectionName, $ignoredConnections)) {
                unset($connections[$i]);
            }
        }
        return $this->activeConnections = $connections;
    }

    /**
     * If not yet set, fetch the active connections
     * Those are the connections that are neither ignored,
     * nor irrelevant (debug_kit, non-test DBs etc...)
     * @return array
     */
    public function getActiveConnections(): array
    {
        return $this->activeConnections ?? $this->fetchActiveConnections();
    }

    /**
     * Returns all the fixture objects of a test
     * grouped by connection
     * @param string[] $fixtures The array of fixtures a list of connections is needed from.
     * @return array
     */
    public function getFixturesPerConnection(array $fixtures)
    {
        if (method_exists($this, 'groupFixturesByConnection')) {
            // For Cake ^4.2
            return $this->groupFixturesByConnection($fixtures);
        } elseif (method_exists($this, '_fixtureConnections')) {
            // For Cake ^4.0
            return $this->_fixtureConnections($fixtures);
        } else {
            throw new Exception(
                'Neither groupFixturesByConnection nor _fixtureConnections defined in ' . self::class
            );
        }
    }

    /**
     * Insert fixture data.
     *
     * @param \Cake\TestSuite\TestCase $test The test to inspect for fixture loading.
     * @return void
     * @throws \Cake\Core\Exception\Exception When fixture records cannot be inserted.
     * @throws \RuntimeException
     */
    public function load(TestCase $test): void
    {
        $fixtures = $test->getFixtures();
        if (!$fixtures || !$test->autoFixtures) {
            return;
        }

        try {
            foreach ($this->getFixturesPerConnection($fixtures) as $conn => $fixtures) {
                $connection = ConnectionManager::get($conn);
                $logQueries = $connection->isQueryLoggingEnabled();

                if ($logQueries && !$this->_debug) {
                    $connection->disableQueryLogging();
                }
                $connection->transactional(function (ConnectionInterface $connection) use ($fixtures, $test): void {
                    $connection->disableConstraints(function (ConnectionInterface $connection) use ($fixtures, $test): void {
                        foreach ($fixtures as $fixture) {
                            try {
                                $fixture->insert($connection);
                            } catch (\PDOException $e) {
                                $msg = sprintf(
                                    'Unable to insert fixture "%s" in "%s" test case: ' . "\n" . '%s',
                                    get_class($fixture),
                                    get_class($test),
                                    $e->getMessage()
                                );
                                throw new Exception($msg, 0, $e);
                            }
                        }
                    });
                });
                if ($logQueries) {
                    $connection->enableQueryLogging(true);
                }
            }
        } catch (\PDOException $e) {
            $msg = sprintf(
                'Unable to insert fixtures for "%s" test case. %s',
                get_class($test),
                $e->getMessage()
            );
            throw new \RuntimeException($msg, 0, $e);
        }
    }

    public function loadSingle(string $name, ?ConnectionInterface $db = null, bool $dropTables = true): void
    {
        if (!isset($this->_fixtureMap[$name])) {
            throw new \UnexpectedValueException(sprintf('Referenced fixture class %s not found', $name));
        }

        $fixture = $this->_fixtureMap[$name];
        if (!$db) {
            $db = ConnectionManager::get($fixture->connection());
        }

        $db->disableConstraints(function (ConnectionInterface $db) use ($fixture): void {
            try {
                $fixture->insert($db);
            } catch (\PDOException $e) {
                $msg = sprintf(
                    'Unable to insert fixture "%s": ' . "\n" . '%s',
                    get_class($fixture),
                    $e->getMessage()
                );
                throw new Exception($msg, 0, $e);
            }
        });
    }
}
