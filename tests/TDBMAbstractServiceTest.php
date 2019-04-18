<?php
declare(strict_types=1);

/*
 Copyright (C) 2006-2014 David Négrier - THE CODING MACHINE

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

namespace TheCodingMachine\TDBM;

use DateTime;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Event\Listeners\OracleSessionInit;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use PHPUnit\Framework\TestCase;
use TheCodingMachine\FluidSchema\FluidSchema;
use TheCodingMachine\TDBM\Utils\Annotation\AnnotationParser;
use TheCodingMachine\TDBM\Utils\DefaultNamingStrategy;
use TheCodingMachine\TDBM\Utils\PathFinder\PathFinder;

abstract class TDBMAbstractServiceTest extends TestCase
{
    /**
     * @var Connection
     */
    protected static $dbConnection;

    /**
     * @var TDBMService
     */
    protected $tdbmService;

    /**
     * @var DummyGeneratorListener
     */
    private $dummyGeneratorListener;

    /**
     * @var ConfigurationInterface
     */
    private $configuration;

    /**
     * @var ArrayCache
     */
    private $cache;

    public static function setUpBeforeClass()
    {
        self::resetConnection();

        $config = new \Doctrine\DBAL\Configuration();

        $dbDriver = $GLOBALS['db_driver'];

        if ($dbDriver === 'pdo_sqlite') {
            $dbConnection = self::getConnection();
            $dbConnection->exec('PRAGMA foreign_keys = ON;');
        } elseif ($dbDriver === 'oci8') {
            $connectionParams = array(
                'servicename' => 'XE',
                'user' => $GLOBALS['db_admin_username'],
                // Because of issues in DBAL, admin and normal user password have to be the same.
                'password' => $GLOBALS['db_password'],
                'host' => $GLOBALS['db_host'],
                'port' => $GLOBALS['db_port'],
                'driver' => $GLOBALS['db_driver'],
                'dbname' => $GLOBALS['db_admin_username'],
                'charset' => 'AL32UTF8',
            );

            $adminConn = DriverManager::getConnection($connectionParams, $config);

            // When dropAndCreateDatabase is run several times, Oracle can have some issues releasing the TDBM user.
            // Let's forcefully delete the connection!
            $adminConn->exec("select 'alter system kill session ''' || sid || ',' || serial# || ''';' from v\$session where username = '".strtoupper($GLOBALS['db_name'])."'");

            $adminConn->getSchemaManager()->dropAndCreateDatabase($GLOBALS['db_name']);

            $dbConnection = self::getConnection();
        } else {
            $connectionParams = array(
                'user' => $GLOBALS['db_username'],
                'password' => $GLOBALS['db_password'],
                'host' => $GLOBALS['db_host'],
                'port' => $GLOBALS['db_port'],
                'driver' => $dbDriver,
            );

            $adminConn = DriverManager::getConnection($connectionParams, $config);

            $adminConn->getSchemaManager()->dropAndCreateDatabase($GLOBALS['db_name']);

            $connectionParams['dbname'] = $GLOBALS['db_name'];

            $dbConnection = DriverManager::getConnection($connectionParams, $config);
        }


        self::initSchema($dbConnection);
    }

    private static function resetConnection(): void
    {
        if (self::$dbConnection !== null) {
            self::$dbConnection->close();
        }
        self::$dbConnection = null;
    }

    protected static function getConnection(): Connection
    {
        if (self::$dbConnection === null) {
            $config = new \Doctrine\DBAL\Configuration();

            $dbDriver = $GLOBALS['db_driver'];

            if ($dbDriver === 'pdo_sqlite') {
                $connectionParams = array(
                    'memory' => true,
                    'driver' => 'pdo_sqlite',
                );
                self::$dbConnection = DriverManager::getConnection($connectionParams, $config);
            } elseif ($dbDriver === 'oci8') {
                $evm = new EventManager();
                $evm->addEventSubscriber(new OracleSessionInit(array(
                    'NLS_TIME_FORMAT' => 'HH24:MI:SS',
                    'NLS_DATE_FORMAT' => 'YYYY-MM-DD HH24:MI:SS',
                    'NLS_TIMESTAMP_FORMAT' => 'YYYY-MM-DD HH24:MI:SS',
                )));

                $connectionParams = array(
                    'servicename' => 'XE',
                    'user' => $GLOBALS['db_username'],
                    'password' => $GLOBALS['db_password'],
                    'host' => $GLOBALS['db_host'],
                    'port' => $GLOBALS['db_port'],
                    'driver' => $GLOBALS['db_driver'],
                    'dbname' => $GLOBALS['db_name'],
                    'charset' => 'AL32UTF8',
                );
                self::$dbConnection = DriverManager::getConnection($connectionParams, $config, $evm);
                self::$dbConnection->setAutoCommit(true);
            } else {
                $connectionParams = array(
                    'user' => $GLOBALS['db_username'],
                    'password' => $GLOBALS['db_password'],
                    'host' => $GLOBALS['db_host'],
                    'port' => $GLOBALS['db_port'],
                    'driver' => $GLOBALS['db_driver'],
                    'dbname' => $GLOBALS['db_name'],
                );
                self::$dbConnection = DriverManager::getConnection($connectionParams, $config);
            }
        }
        return self::$dbConnection;
    }

    protected function onlyMySql()
    {
        if (!self::getConnection()->getDatabasePlatform() instanceof MySqlPlatform) {
            $this->markTestSkipped('MySQL specific test');
        }
    }

    protected function setUp()
    {
        $this->tdbmService = new TDBMService($this->getConfiguration());
    }

    protected function getDummyGeneratorListener() : DummyGeneratorListener
    {
        if ($this->dummyGeneratorListener === null) {
            $this->dummyGeneratorListener = new DummyGeneratorListener();
        }
        return $this->dummyGeneratorListener;
    }

    protected function getCache(): ArrayCache
    {
        if ($this->cache === null) {
            $this->cache = new ArrayCache();
        }
        return $this->cache;
    }

    protected function getConfiguration() : ConfigurationInterface
    {
        if ($this->configuration === null) {
            $this->configuration = new Configuration('TheCodingMachine\\TDBM\\Test\\Dao\\Bean', 'TheCodingMachine\\TDBM\\Test\\Dao', self::getConnection(), $this->getNamingStrategy(), $this->getCache(), null, null, [$this->getDummyGeneratorListener()]);
            $this->configuration->setPathFinder(new PathFinder(null, dirname(__DIR__, 4)));
        }
        return $this->configuration;
    }

    protected function getNamingStrategy()
    {
        $strategy = new DefaultNamingStrategy(AnnotationParser::buildWithDefaultAnnotations([]), self::getConnection()->getSchemaManager());
        $strategy->setBeanPrefix('');
        $strategy->setBeanSuffix('Bean');
        $strategy->setBaseBeanPrefix('');
        $strategy->setBaseBeanSuffix('BaseBean');
        $strategy->setDaoPrefix('');
        $strategy->setDaoSuffix('Dao');
        $strategy->setBaseDaoPrefix('');
        $strategy->setBaseDaoSuffix('BaseDao');

        return $strategy;
    }

    private static function initSchema(Connection $connection): void
    {
        $fromSchema = $connection->getSchemaManager()->createSchema();
        $toSchema = clone $fromSchema;

        $db = new FluidSchema($toSchema, new \TheCodingMachine\FluidSchema\DefaultNamingStrategy($connection->getDatabasePlatform()));

        $db->table('country')
            ->column('id')->integer()->primaryKey()->autoIncrement()->comment('@Autoincrement')
            ->column('label')->string(255)->unique();

        $db->table('person')
            ->column('id')->integer()->primaryKey()->autoIncrement()->comment('@Autoincrement')
            ->column('name')->string(255);

        if ($connection->getDatabasePlatform() instanceof OraclePlatform) {
            $toSchema->getTable($connection->quoteIdentifier('person'))
                ->addColumn(
                    $connection->quoteIdentifier('created_at'),
                    'datetime',
                    ['columnDefinition' => 'TIMESTAMP(0) DEFAULT SYSDATE NOT NULL']
                );
        } else {
            $toSchema->getTable('person')
                ->addColumn(
                    $connection->quoteIdentifier('created_at'),
                    'datetime',
                    ['columnDefinition' => 'timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP']
                );
        }

        $db->table('person')
            ->column('modified_at')->datetime()->null()
            ->column('order')->integer()->null();


        $db->table('contact')
            ->extends('person')
            ->column('email')->string(255)
            ->column('manager_id')->references('contact')->null();

        $db->table('users')
            ->extends('contact')
            ->column('login')->string(255)
            ->column('password')->string(255)->null()
            ->column('status')->string(10)->null()->default(null)
            ->column('country_id')->references('country');

        $db->table('rights')
            ->column('label')->string(255)->primaryKey()->comment('Non autoincrementable primary key');

        $db->table('roles')
            ->column('id')->integer()->primaryKey()->autoIncrement()->comment('@Autoincrement')
            ->column('name')->string(255)
            ->column('created_at')->date()->null()
            ->column('status')->boolean()->null()->default(1);

        $db->table('roles_rights')
            ->column('role_id')->references('roles')
            ->column('right_label')->references('rights')->then()
            ->primaryKey(['role_id', 'right_label']);

        $db->table('users_roles')
            ->column('id')->integer()->primaryKey()->autoIncrement()->comment('@Autoincrement')
            ->column('user_id')->references('users')
            ->column('role_id')->references('roles');

        $db->table('all_nullable')
            ->column('id')->integer()->primaryKey()->autoIncrement()->comment('@Autoincrement')
            ->column('label')->string(255)->null()
            ->column('country_id')->references('country')->null();

        $db->table('animal')
            ->column('id')->integer()->primaryKey()->autoIncrement()->comment('@Autoincrement')
            ->column('name')->string(45)->index()
            ->column('UPPERCASE_COLUMN')->string(45)->null()
            ->column('order')->integer()->null();

        $db->table('dog')
            ->extends('animal')
            ->column('race')->string(45)->null();

        $db->table('cat')
            ->extends('animal')
            ->column('cuteness_level')->integer()->null();

        $db->table('panda')
            ->extends('animal')
            ->column('weight')->float()->null();

        $db->table('boats')
            ->column('id')->integer()->primaryKey()->autoIncrement()->comment('@Autoincrement')
            ->column('name')->string(255)
            ->column('anchorage_country')->references('country')->notNull()->then()
            ->column('current_country')->references('country')->null()->then()
            ->column('length')->decimal(10, 2)->null()->then()
            ->unique(['anchorage_country', 'name']);

        $db->table('sailed_countries')
            ->column('boat_id')->references('boats')
            ->column('country_id')->references('country')
            ->then()->primaryKey(['boat_id', 'country_id']);

        $db->table('category')
            ->column('id')->integer()->primaryKey()->autoIncrement()->comment('@Autoincrement')
            ->column('label')->string(255)
            ->column('parent_id')->references('category')->null();

        $db->table('article')
            ->column('id')->string(36)->primaryKey()->comment('@UUID')
            ->column('content')->string(255)
            ->column('author_id')->references('users')->null();

        $db->table('article2')
            ->column('id')->string(36)->primaryKey()->comment('@UUID v4')
            ->column('content')->string(255);

        $db->table('files')
            ->column('id')->integer()->primaryKey()->autoIncrement()->comment('@Autoincrement')
            ->column('file')->blob()
            ->column('md5')->string()->null()->comment("@ProtectedGetter\n@ProtectedSetter")
            ->column('article_id')->references('article')->null()->comment("@ProtectedGetter\n@ProtectedSetter\n@ProtectedOneToMany");

        $toSchema->getTable('users')
            ->addUniqueIndex([$connection->quoteIdentifier('login')], 'users_login_idx')
            ->addIndex([$connection->quoteIdentifier('status'), $connection->quoteIdentifier('country_id')], 'users_status_country_idx');

        // We create the same index twice
        // except for Oracle that won't let us create twice the same index.
        if (!$connection->getDatabasePlatform() instanceof OraclePlatform) {
            $toSchema->getTable('users')
                ->addUniqueIndex([$connection->quoteIdentifier('login')], 'users_login_idx_2');
        }

        // A table with a foreign key that references a non primary key.
        $db->table('ref_no_prim_key')
            ->column('id')->integer()->primaryKey()->autoIncrement()->comment('@Autoincrement')
            ->column('from')->string(50)
            ->column('to')->string(50)->unique();

        $toSchema->getTable($connection->quoteIdentifier('ref_no_prim_key'))->addForeignKeyConstraint($connection->quoteIdentifier('ref_no_prim_key'), [$connection->quoteIdentifier('from')], [$connection->quoteIdentifier('to')]);

        // A table with multiple primary keys.
        $db->table('states')
            ->column('country_id')->references('country')
            ->column('code')->string(3)
            ->column('name')->string(50)->then()
            ->primaryKey(['country_id', 'code']);

        // Tables using @Json annotations
        $db->table('accounts')
            ->column('id')->integer()->primaryKey()->autoIncrement()
            ->column('name')->string();

        $db->table('nodes')
            ->column('id')->integer()->primaryKey()->autoIncrement()->comment('@JsonIgnore')
            ->column('alias_id')->references('nodes')->null()->comment('@JsonRecursive')
            ->column('parent_id')->references('nodes')->null()->comment('@JsonInclude')
            ->column('root_id')->references('nodes')->null()->comment('@JsonIgnore')
            ->column('owner_id')->references('accounts')->null()->comment('@JsonFormat(property="name") @JsonInclude')
            ->column('name')->string()->comment('@JsonKey("basename")')
            ->column('size')->integer()->notNull()->default(0)->comment('@JsonFormat(unit=" o")')
            ->column('weight')->float()->null()->comment('@JsonFormat(decimals=2,unit="g")')
            ->column('created_at')->date()->null()->comment('@JsonFormat("Y-m-d")');

        $db->table('nodes_guests')
            ->column('node_id')->references('nodes')->comment('@JsonIgnore')
            ->column('guest_id')->references('accounts')->comment('@JsonKey("guests") @JsonFormat(method="getName")');

        $db->table('node_entries')
            ->column('id')->integer()->primaryKey()->autoIncrement()
            ->column('node_id')->references('nodes')->comment('@JsonCollection("entries") @JsonFormat(property="entry")')
            ->column('entry')->string()->null();

        $db->table('artists')
            ->column('id')->integer()->primaryKey()->autoIncrement()
            ->column('name')->string();

        $db->table('albums')
            ->column('id')->integer()->primaryKey()->autoIncrement()
            ->column('artist_id')->references('artists')->comment('@JsonCollection(key="discography")')
            ->column('title')->string();

        $db->table('tracks')
            ->column('id')->integer()->primaryKey()->autoIncrement()
            ->column('album_id')->references('albums')->comment('@JsonCollection @JsonRecursive')
            ->column('title')->string()
            ->column('duration')->time()->comment('@JsonFormat("H:i:s")');

        $db->table('featuring')
            ->column('id')->integer()->primaryKey()->autoIncrement()
            ->column('track_id')->references('tracks')
            ->column('artist_id')->references('artists')->comment('@JsonKey("feat") @JsonInclude');

        $sqlStmts = $toSchema->getMigrateFromSql($fromSchema, $connection->getDatabasePlatform());

        foreach ($sqlStmts as $sqlStmt) {
            $connection->exec($sqlStmt);
        }

        self::insert($connection, 'country', [
            'label' => 'France',
        ]);
        self::insert($connection, 'country', [
            'label' => 'UK',
        ]);
        self::insert($connection, 'country', [
            'label' => 'Jamaica',
        ]);

        self::insert($connection, 'person', [
            'name' => 'John Smith',
            'created_at' => '2015-10-24 11:57:13',
        ]);
        self::insert($connection, 'person', [
            'name' => 'Jean Dupont',
            'created_at' => '2015-10-24 11:57:13',
        ]);
        self::insert($connection, 'person', [
            'name' => 'Robert Marley',
            'created_at' => '2015-10-24 11:57:13',
        ]);
        self::insert($connection, 'person', [
            'name' => 'Bill Shakespeare',
            'created_at' => '2015-10-24 11:57:13',
        ]);

        self::insert($connection, 'contact', [
            'id' => 1,
            'email' => 'john@smith.com',
            'manager_id' => null,
        ]);
        self::insert($connection, 'contact', [
            'id' => 2,
            'email' => 'jean@dupont.com',
            'manager_id' => null,
        ]);
        self::insert($connection, 'contact', [
            'id' => 3,
            'email' => 'robert@marley.com',
            'manager_id' => null,
        ]);
        self::insert($connection, 'contact', [
            'id' => 4,
            'email' => 'bill@shakespeare.com',
            'manager_id' => 1,
        ]);

        self::insert($connection, 'rights', [
            'label' => 'CAN_SING',
        ]);
        self::insert($connection, 'rights', [
            'label' => 'CAN_WRITE',
        ]);

        self::insert($connection, 'roles', [
            'name' => 'Admins',
            'created_at' => '2015-10-24'
        ]);
        self::insert($connection, 'roles', [
            'name' => 'Writers',
            'created_at' => '2015-10-24'
        ]);
        self::insert($connection, 'roles', [
            'name' => 'Singers',
            'created_at' => '2015-10-24'
        ]);

        self::insert($connection, 'roles_rights', [
            'role_id' => 1,
            'right_label' => 'CAN_SING'
        ]);
        self::insert($connection, 'roles_rights', [
            'role_id' => 3,
            'right_label' => 'CAN_SING'
        ]);
        self::insert($connection, 'roles_rights', [
            'role_id' => 1,
            'right_label' => 'CAN_WRITE'
        ]);
        self::insert($connection, 'roles_rights', [
            'role_id' => 2,
            'right_label' => 'CAN_WRITE'
        ]);

        self::insert($connection, 'users', [
            'id' => 1,
            'login' => 'john.smith',
            'password' => null,
            'status' => 'on',
            'country_id' => 2
        ]);
        self::insert($connection, 'users', [
            'id' => 2,
            'login' => 'jean.dupont',
            'password' => null,
            'status' => 'on',
            'country_id' => 1
        ]);
        self::insert($connection, 'users', [
            'id' => 3,
            'login' => 'robert.marley',
            'password' => null,
            'status' => 'off',
            'country_id' => 3
        ]);
        self::insert($connection, 'users', [
            'id' => 4,
            'login' => 'bill.shakespeare',
            'password' => null,
            'status' => 'off',
            'country_id' => 2
        ]);

        self::insert($connection, 'users_roles', [
            'user_id' => 1,
            'role_id' => 1,
        ]);
        self::insert($connection, 'users_roles', [
            'user_id' => 2,
            'role_id' => 1,
        ]);
        self::insert($connection, 'users_roles', [
            'user_id' => 3,
            'role_id' => 3,
        ]);
        self::insert($connection, 'users_roles', [
            'user_id' => 4,
            'role_id' => 2,
        ]);
        self::insert($connection, 'users_roles', [
            'user_id' => 3,
            'role_id' => 2,
        ]);

        self::insert($connection, 'ref_no_prim_key', [
            'from' => 'foo',
            'to' => 'foo',
        ]);

        self::insert($connection, 'accounts', [
            'id' => 1,
            'name' => 'root'
        ]);
        self::insert($connection, 'accounts', [
            'id' => 2,
            'name' => 'user'
        ]);
        self::insert($connection, 'accounts', [
            'id' => 3,
            'name' => 'www'
        ]);
        self::insert($connection, 'nodes', [
            'id' => 1,
            'owner_id' => 1,
            'name' => '/',
            'created_at' => (new DateTime('last year'))->format('Y-m-d H:i:s'),
        ]);
        self::insert($connection, 'nodes', [
            'id' => 2,
            'name' => 'private',
            'created_at' => (new DateTime('last year'))->format('Y-m-d H:i:s'),
            'parent_id' => 1,
        ]);
        self::insert($connection, 'nodes', [
            'id' => 3,
            'name' => 'var',
            'created_at' => (new DateTime('last year'))->format('Y-m-d H:i:s'),
            'parent_id' => 2,
        ]);
        self::insert($connection, 'nodes', [
            'id' => 4,
            'name' => 'var',
            'created_at' => (new DateTime('last year'))->format('Y-m-d H:i:s'),
            'parent_id' => 1,
            'alias_id' => 3
        ]);
        self::insert($connection, 'nodes', [
            'id' => 5,
            'name' => 'www',
            'created_at' => (new DateTime('last week'))->format('Y-m-d H:i:s'),
            'parent_id' => 4
        ]);
        self::insert($connection, 'nodes', [
            'id' => 6,
            'owner_id' => 2,
            'name' => 'index.html',
            'created_at' => (new DateTime('now'))->format('Y-m-d H:i:s'),
            'size' => 512,
            'weight' => 42.5,
            'parent_id' => 5
        ]);
        self::insert($connection, 'nodes', [
            'id' => 7,
            'name' => 'index.html',
            'created_at' => (new DateTime('now'))->format('Y-m-d H:i:s'),
            'alias_id' => 6,
            'parent_id' => 1
        ]);
        self::insert($connection, 'nodes', [
            'id' => 8,
            'name' => 'index.htm',
            'created_at' => (new DateTime('now'))->format('Y-m-d H:i:s'),
            'alias_id' => 7,
            'parent_id' => 1
        ]);
        self::insert($connection, 'nodes_guests', [
            'node_id' => 6,
            'guest_id' => 1
        ]);
        self::insert($connection, 'nodes_guests', [
            'node_id' => 6,
            'guest_id' => 3
        ]);
        self::insert($connection, 'node_entries', [
            'node_id' => 6,
            'entry' => '<h1>'
        ]);
        self::insert($connection, 'node_entries', [
            'node_id' => 6,
            'entry' => 'Hello, World'
        ]);
        self::insert($connection, 'node_entries', [
            'node_id' => 6,
            'entry' => '</h1>'
        ]);

        self::insert($connection, 'artists', [
            'id' => 1,
            'name' => 'Pink Floyd'
        ]);
        self::insert($connection, 'artists', [
            'id' => 2,
            'name' => 'Roger Waters'
        ]);
        self::insert($connection, 'artists', [
            'id' => 3,
            'name' => 'David Gilmour'
        ]);
        self::insert($connection, 'albums', [
            'id' => 1,
            'artist_id' => 1,
            'title' => 'Animals'
        ]);
        $isOracle = $connection->getDatabasePlatform() instanceof OraclePlatform;

        self::insert($connection, 'tracks', [
            'album_id' => 1,
            'title' =>'Pigs on the Wing 1',
            // Note: Oracle does not have a TIME column type
            'duration' => $isOracle ? '00:01:25' : (new DateTime('now'))->format('Y-m-d H:i:s'),
        ]);
        self::insert($connection, 'tracks', [
            'album_id' => 1,
            'title' => 'Dogs',
            'duration' => $isOracle ? '00:17:04' : (new DateTime('now'))->format('Y-m-d H:i:s'),
        ]);
        self::insert($connection, 'tracks', [
            'album_id' => 1,
            'title' => 'Pigs (Three Different Ones)',
            'duration' => $isOracle ? '00:11:22' : (new DateTime('now'))->format('Y-m-d H:i:s'),
        ]);
        self::insert($connection, 'tracks', [
            'album_id' => 1,
            'title' => 'Sheep',
            'duration' => $isOracle ? '00:10:24' : (new DateTime('now'))->format('Y-m-d H:i:s'),
        ]);
        self::insert($connection, 'tracks', [
            'album_id' => 1,
            'title' => 'Pigs on the Wing 2',
            'duration' => $isOracle ? '00:01:26' : (new DateTime('now'))->format('Y-m-d H:i:s'),
        ]);
        self::insert($connection, 'featuring', [
            'track_id' => 1,
            'artist_id' => 2
        ]);
        self::insert($connection, 'featuring', [
            'track_id' => 2,
            'artist_id' => 3
        ]);
        self::insert($connection, 'featuring', [
            'track_id' => 2,
            'artist_id' => 2
        ]);
        self::insert($connection, 'featuring', [
            'track_id' => 3,
            'artist_id' => 2
        ]);
        self::insert($connection, 'featuring', [
            'track_id' => 4,
            'artist_id' => 2
        ]);
        self::insert($connection, 'featuring', [
            'track_id' => 5,
            'artist_id' => 2
        ]);
    }

    protected static function insert(Connection $connection, string $tableName, array $data): void
    {
        $quotedData = [];
        foreach ($data as $id => $value) {
            $quotedData[$connection->quoteIdentifier($id)] = $value;
        }
        $connection->insert($connection->quoteIdentifier($tableName), $quotedData);
    }

    protected static function delete(Connection $connection, string $tableName, array $data): void
    {
        $quotedData = [];
        foreach ($data as $id => $value) {
            $quotedData[$connection->quoteIdentifier($id)] = $value;
        }
        $connection->delete($connection->quoteIdentifier($tableName), $quotedData);
    }
}
