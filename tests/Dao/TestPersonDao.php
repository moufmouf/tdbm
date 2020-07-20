<?php
/*
 * This file has been automatically generated by TDBM.
 * You can edit this file as it will not be overwritten.
 */

declare(strict_types=1);

namespace TheCodingMachine\TDBM\Dao;

use TheCodingMachine\TDBM\ResultIterator;
use TheCodingMachine\TDBM\Test\Dao\Generated\PersonBaseDao;

/**
 * The ContactDao class will maintain the persistence of ContactBean class into the contact table.
 */
class TestPersonDao extends PersonBaseDao
{
    public function testFindFromRawSQLOnInherited(): ResultIterator
    {
        $sql = '
            SELECT DISTINCT  person.*, contact.*, users.*
            FROM person JOIN contact ON person.id = contact.id
             JOIN users ON contact.id = users.id
        ';

        return $this->findFromRawSql($sql, []);
    }
}